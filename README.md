# アクアクリーン LP

法人・ハウスクリーニング・エアコンクリーニング・空室清掃サービスのランディングページです。

## ページ構成

- `index.html` — トップページ
- `services.html` — サービス紹介
- `cases.html` — 施工事例
- `estimate.html` — 見積もり案内
- `contact.html` — お問い合わせフォーム
- `contact-send.php` — お問い合わせフォームの送信処理（PHP）
- `assets/` — 画像素材

## デプロイ時の注意

`contact-send.php` を使用する場合は以下を必ず設定してください。

1. `CONTACT_TO_EMAIL` を実際の受信先メールアドレスに変更する
2. サーバーで PHP の `mail()` が送信可能なこと（sendmail 等の設定済み）を確認する
   - `mail()` が使えない環境では PHPMailer 等を使った SMTP 送信に差し替える
3. このファイルが `.php` として実行されること（静的ホスティングでは動作しません）
