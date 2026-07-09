<?php
/**
 * お問い合わせフォーム送信スクリプト
 *
 * デプロイ前に必ず設定してください:
 *   1. CONTACT_TO_EMAIL を実際の受信先アドレスに変更する
 *   2. サーバーが PHP の mail() を送信できること（sendmail 等が設定済みであること）を確認する
 *      mail() が使えない/届かない環境では、PHPMailer 等を使った SMTP 送信に差し替えてください。
 *   3. このファイルが確実に .php として実行されること（静的ホスティングでは動作しません）
 */

declare(strict_types=1);

// ---- 設定 ----------------------------------------------------------------
const CONTACT_TO_EMAIL   = 'info@aqua-clean.jp'; // TODO: 実際の宛先メールアドレスに変更
const CONTACT_FROM_EMAIL = 'noreply@aqua-clean.jp'; // TODO: 送信元アドレス（自ドメインのアドレス推奨）
const CONTACT_SITE_NAME  = 'アクアクリーン株式会社';
const MAX_FILES          = 5;
const MAX_FILE_BYTES     = 5 * 1024 * 1024;  // 1ファイルあたり最大5MB
const MAX_TOTAL_BYTES    = 15 * 1024 * 1024; // 添付合計最大15MB
const ALLOWED_MIME_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

// ---- 共通ユーティリティ ----------------------------------------------------
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');

function respond(bool $success, string $message = '', int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// 改行を除去してヘッダーインジェクションを防止
function sanitize_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

function post(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', 405);
}

// ---- ハニーポット（ボット対策） ---------------------------------------------
// 人間には見えない項目に値が入っていたら、ボットとみなして成功したフリをして終了
if (post('website') !== '') {
    respond(true);
}

// ---- 入力値の取得・バリデーション -------------------------------------------
$inquiryType   = post('inquiry_type');
$company       = post('company');
$name          = post('name');
$email         = post('email');
$location      = post('location');
$content       = post('content');
$phone         = post('phone');
$preferredDate = post('preferred_date');
$other         = post('other');
$privacy       = post('privacy');

$serviceLabels = [
    'aircon_wall' => 'エアコン（壁掛け・天掛け等）',
    'floor_tile'  => '床、ガラスサッシ、タイル',
    'moving'      => '引越し前・引越し後クリーニング',
    'water'       => '水回り（トイレ・浴室・洗面台）',
    'kitchen'     => 'キッチン（レンジフード・換気扇・全体）',
    'veranda'     => 'ベランダ・外回り',
];

$rawServices = $_POST['service'] ?? [];
$services = [];
if (is_array($rawServices)) {
    foreach ($rawServices as $value) {
        if (is_string($value) && isset($serviceLabels[$value])) {
            $services[] = $serviceLabels[$value];
        }
    }
}

$errors = [];

if (!in_array($inquiryType, ['personal', 'corporate'], true)) {
    $errors[] = 'お問い合わせ種別が不正です。';
}
if (count($services) === 0) {
    $errors[] = 'ご希望サービスを1つ以上選択してください。';
}
if ($name === '' || mb_strlen($name) > 100) {
    $errors[] = 'お名前を正しく入力してください。';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    $errors[] = 'メールアドレスを正しく入力してください。';
}
if ($location === '' || mb_strlen($location) > 200) {
    $errors[] = '清掃場所を正しく入力してください。';
}
if ($content === '' || mb_strlen($content) > 3000) {
    $errors[] = 'ご希望内容を正しく入力してください。';
}
if ($phone === '' || mb_strlen($phone) > 30) {
    $errors[] = '電話番号を正しく入力してください。';
}
if ($preferredDate === '' || mb_strlen($preferredDate) > 200) {
    $errors[] = 'ご希望日時を正しく入力してください。';
}
if ($privacy !== 'on') {
    $errors[] = 'プライバシーポリシーへの同意が必要です。';
}
if ($company !== '' && mb_strlen($company) > 100) {
    $errors[] = '会社名・店舗名が長すぎます。';
}
if ($other !== '' && mb_strlen($other) > 3000) {
    $errors[] = 'その他ご相談内容が長すぎます。';
}

if (!empty($errors)) {
    respond(false, implode(' ', $errors), 422);
}

// ---- 添付ファイルの検証 -----------------------------------------------------
$attachments = [];
$totalBytes = 0;

if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
    $fileCount = count($_FILES['photos']['name']);
    if ($fileCount > MAX_FILES) {
        respond(false, '写真は' . MAX_FILES . '枚までアップロードできます。', 422);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    for ($i = 0; $i < $fileCount; $i++) {
        $error = $_FILES['photos']['error'][$i];
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            finfo_close($finfo);
            respond(false, '写真のアップロードに失敗しました。', 422);
        }

        $tmpPath = $_FILES['photos']['tmp_name'][$i];
        $size    = (int) $_FILES['photos']['size'][$i];

        if (!is_uploaded_file($tmpPath)) {
            finfo_close($finfo);
            respond(false, '不正なアップロードです。', 422);
        }
        if ($size > MAX_FILE_BYTES) {
            finfo_close($finfo);
            respond(false, '1枚あたりの写真サイズは5MBまでです。', 422);
        }

        $mimeType = finfo_file($finfo, $tmpPath);
        if (!isset(ALLOWED_MIME_TYPES[$mimeType])) {
            finfo_close($finfo);
            respond(false, '対応していないファイル形式が含まれています（jpg / png / gif / webp のみ）。', 422);
        }

        $totalBytes += $size;
        if ($totalBytes > MAX_TOTAL_BYTES) {
            finfo_close($finfo);
            respond(false, '写真の合計サイズが大きすぎます（15MBまで）。', 422);
        }

        $attachments[] = [
            'path'     => $tmpPath,
            'name'     => 'photo-' . ($i + 1) . '.' . ALLOWED_MIME_TYPES[$mimeType],
            'mimeType' => $mimeType,
        ];
    }

    finfo_close($finfo);
}

// ---- メール本文の組み立て ---------------------------------------------------
$typeLabel = $inquiryType === 'corporate' ? '法人のお客様' : '個人のお客様';

$bodyLines = [
    '公式サイトのお問い合わせフォームから送信がありました。',
    '',
    '■お問い合わせ種別：' . $typeLabel,
];
if ($company !== '') {
    $bodyLines[] = '■会社名・店舗名：' . $company;
}
$bodyLines[] = '■ご希望サービス：' . implode(' / ', $services);
$bodyLines[] = '■お名前：' . $name;
$bodyLines[] = '■メールアドレス：' . $email;
$bodyLines[] = '■電話番号：' . $phone;
$bodyLines[] = '■清掃場所：' . $location;
$bodyLines[] = '■ご希望日時：' . $preferredDate;
$bodyLines[] = '';
$bodyLines[] = '■ご希望内容：';
$bodyLines[] = $content;
if ($other !== '') {
    $bodyLines[] = '';
    $bodyLines[] = '■その他ご相談内容：';
    $bodyLines[] = $other;
}
if (!empty($attachments)) {
    $bodyLines[] = '';
    $bodyLines[] = '■添付写真：' . count($attachments) . '枚（本メールに添付）';
}
$body = implode("\n", $bodyLines);

$subject = mb_encode_mimeheader('【' . CONTACT_SITE_NAME . '】お問い合わせがありました（' . $name . '様）', 'UTF-8');

$fromHeader  = mb_encode_mimeheader(CONTACT_SITE_NAME, 'UTF-8') . ' <' . CONTACT_FROM_EMAIL . '>';
$replyName   = mb_encode_mimeheader($name, 'UTF-8');
$replyToAddr = sanitize_header_value($email);
$replyTo     = $replyName . ' <' . $replyToAddr . '>';

// ---- 送信 -------------------------------------------------------------
$sent = false;

if (empty($attachments)) {
    $headers = implode("\r\n", [
        'From: ' . $fromHeader,
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    $sent = mail(CONTACT_TO_EMAIL, $subject, $body, $headers);
} else {
    $boundary = 'AQBOUNDARY-' . bin2hex(random_bytes(16));

    $headers = implode("\r\n", [
        'From: ' . $fromHeader,
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ]);

    $message  = '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $body . "\r\n\r\n";

    foreach ($attachments as $attachment) {
        $fileData = file_get_contents($attachment['path']);
        if ($fileData === false) {
            continue;
        }
        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: ' . $attachment['mimeType'] . '; name="' . $attachment['name'] . "\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= 'Content-Disposition: attachment; filename="' . $attachment['name'] . "\"\r\n\r\n";
        $message .= chunk_split(base64_encode($fileData));
        $message .= "\r\n";
    }
    $message .= '--' . $boundary . '--';

    $sent = mail(CONTACT_TO_EMAIL, $subject, $message, $headers);
}

if (!$sent) {
    respond(false, 'メールの送信に失敗しました。時間をおいて再度お試しください。', 500);
}

respond(true);
