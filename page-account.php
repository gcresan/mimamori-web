<?php /*Template Name: ユーザープロフィールページ */ ?>
<?php get_header(); ?>
        <div class="breadcrumb">
            <a href="<?php echo home_url(); ?>/dashboard">ダッシュボード</a> &gt; アカウント設定
        </div>
<div class="welcome">
    <h1>⚙️ アカウント設定</h1>
    <p>こちらから前月と前々月のデータを比較したレポートを生成できます。</p>
</div>

        
        <!-- ユーザー情報 -->
        <div class="card">
            <h2>👤 ユーザー情報</h2>
            
            <form action="/account/update" method="POST">
                <div class="form-group">
                    <label for="name">氏名</label>
                    <input type="text" id="name" name="name" value="山田太郎" required>
                </div>
                
                <div class="form-group">
                    <label for="email">メールアドレス</label>
                    <input type="email" id="email" name="email" value="yamada@example.com" required>
                    <div class="form-note">※ ログインIDとしても使用されます</div>
                </div>
                
                <div class="form-group">
                    <label>会社名</label>
                    <div class="info-display">
                        <span>〇〇株式会社</span>
                    </div>
                    <div class="form-note">※ 会社名は変更できません。変更が必要な場合はサポートまでお問い合わせください。</div>
                </div>
                
                <button type="submit" class="btn">💾 変更を保存</button>
            </form>
        </div>
        
        <!-- パスワード変更 -->
        <div class="card">
            <h2>🔒 パスワード変更</h2>
            
            <form action="/account/change-password" method="POST">
                <div class="form-group">
                    <label for="current-password">現在のパスワード</label>
                    <input type="password" id="current-password" name="current_password" required>
                </div>
                
                <div class="form-group">
                    <label for="new-password">新しいパスワード</label>
                    <input type="password" id="new-password" name="new_password" required>
                    <div class="form-note">※ 8文字以上、英字と数字を含む必要があります</div>
                </div>
                
                <div class="form-group">
                    <label for="confirm-password">新しいパスワード（確認）</label>
                    <input type="password" id="confirm-password" name="confirm_password" required>
                </div>
                
                <button type="submit" class="btn">🔄 パスワードを変更</button>
            </form>
        </div>
        
        <!-- 通知設定 -->
        <div class="card">
            <h2>🔔 通知設定</h2>
            
            <p style="margin-bottom: 20px; color: #555;">以下の通知をメールで受け取るかどうかを設定できます。</p>
            
            <form action="/account/notification-settings" method="POST">
                <div class="notification-item">
                    <div class="notification-label">
                        <strong>月次レポート更新通知</strong>
                        <small>新しい月次レポートが公開されたときに通知します</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="notify_report" checked>
                        <span class="slider"></span>
                    </label>
                </div>
                
                <div class="notification-item">
                    <div class="notification-label">
                        <strong>トピックス更新通知</strong>
                        <small>新しいトピックスが追加されたときに通知します</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="notify_topics" checked>
                        <span class="slider"></span>
                    </label>
                </div>
                
                <div class="notification-item">
                    <div class="notification-label">
                        <strong>問い合わせ返信通知</strong>
                        <small>問い合わせに返信があったときに通知します</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="notify_inquiry" checked>
                        <span class="slider"></span>
                    </label>
                </div>
                
                <div class="notification-item">
                    <div class="notification-label">
                        <strong>重要なお知らせ</strong>
                        <small>システムメンテナンスや重要な変更に関する通知</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="notify_important" checked>
                        <span class="slider"></span>
                    </label>
                </div>
                
                <div style="margin-top: 20px;">
                    <button type="submit" class="btn">💾 設定を保存</button>
                </div>
            </form>
        </div>
        
        <!-- アカウント情報 -->
        <div class="card">
            <h2>ℹ️ アカウント情報</h2>
            
            <div class="info-display">
                <strong>ユーザーID:</strong>
                <span>USER-12345</span>
            </div>
            
            <div class="info-display">
                <strong>登録日:</strong>
                <span>2025年10月1日</span>
            </div>
            
            <div class="info-display">
                <strong>最終ログイン:</strong>
                <span>2026年1月26日 10:45</span>
            </div>
            
            <div class="info-display">
                <strong>契約プラン:</strong>
                <span>スタンダードプラン</span>
            </div>
        </div>
        
        <!-- ログアウト -->
        <div class="card">
            <h2>🚪 ログアウト</h2>
            
            <p style="margin-bottom: 20px; color: #555;">
                現在のセッションを終了します。再度ログインするには、メールアドレスとパスワードが必要です。
            </p>
            
            <a href="/logout" class="btn btn-danger">ログアウト</a>
        </div>
        
        <!-- サポート情報 -->
        <div class="card">
            <h2>🆘 サポート</h2>
            
            <p style="margin-bottom: 15px; color: #555;">
                アカウントに関するお困りごとやご質問がございましたら、お気軽にお問い合わせください。
            </p>
            
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="/inquiry/new" class="btn">💬 お問い合わせ</a>
                <a href="mailto:support@example.com" class="btn btn-secondary">📧 メールで連絡</a>
            </div>
        </div>
<?php get_footer(); ?>