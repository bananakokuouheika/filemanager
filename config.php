<?php
session_start();

// 実際のプロジェクトでは、データベースなどより安全な方法でユーザー情報を管理すべきです。
// ここでは簡易的に配列で定義しています。
$users = [
    'admin' => [
        'password' => password_hash('yo0ky0ri6', PASSWORD_DEFAULT), // パスワードはハッシュ化必須
        'root_dir' => '/home/bananaking'
    ],
    'freeuser' => [
        'password' => password_hash('password456', PASSWORD_DEFAULT),
        'root_dir' => __DIR__ . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'freeuser_data'
    ]
];

$current_user = null;
$base_dir = null;

// ログイン処理
if (isset($_POST['login_action'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if (isset($users[$username]) && password_verify($password, $users[$username]['password'])) {
        $_SESSION['logged_in_user'] = $username;
        header('Location: index.php'); // ログイン成功後リダイレクト
        exit;
    } else {
        $_SESSION['login_error'] = 'ユーザー名またはパスワードが間違っています。';
        header('Location: index.php'); // エラーメッセージを表示するためリダイレクト
        exit;
    }
}

// ログアウト処理
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// ログイン状態のチェック
if (isset($_SESSION['logged_in_user']) && isset($users[$_SESSION['logged_in_user']])) {
    $current_user = $_SESSION['logged_in_user'];
    $base_dir = $users[$current_user]['root_dir'];

    // ルートディレクトリが存在しない場合は作成
    if (!is_dir($base_dir)) {
        mkdir($base_dir, 0755, true);
    }
} else {
    // ログインしていない場合はログインフォームを表示
    $login_error_message = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';
    unset($_SESSION['login_error']); // メッセージ表示後は削除
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ログイン - ファイルマネージャー</title>
        <link rel="stylesheet" href="style.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    </head>
    <body class="login-page">
        <div class="login-container card">
            <h2 class="card-title"><i class="fas fa-lock"></i> ファイルマネージャー ログイン</h2>
            <?php if ($login_error_message): ?>
                <p class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($login_error_message); ?></p>
            <?php endif; ?>
            <form action="config.php" method="POST">
                <input type="hidden" name="login_action" value="1">
                <div class="form-group">
                    <label for="username"><i class="fas fa-user"></i> ユーザー名:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password"><i class="fas fa-key"></i> パスワード:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> ログイン</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit; // ログインフォームを表示したらスクリプトを終了
}
?>
