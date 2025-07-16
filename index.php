<?php
session_start(); // セッションを開始

// config.phpを読み込み（ユーザー認証とルートディレクトリ設定のため）
require_once 'config.php';

// ログインしていない場合はconfig.phpでログインフォームを表示してexitしているため、
// ここに到達するのはログイン済みユーザーのみです。

// --- パス設定とクリーンアップ ---
$base_dir_absolute = realpath($base_dir); // config.phpで設定されたルートディレクトリの絶対パス

// realpathが失敗した場合（ディレクトリが存在しないなど）のハンドリング
if ($base_dir_absolute === false) {
    error_log("ERROR: Base directory does not exist or is not accessible: " . $base_dir);
    die("エラー: ファイルマネージャーのルートディレクトリが見つからないか、アクセスできません。管理者にお問い合わせください。");
}

// URLから現在のパスを取得し、セキュリティのためにクリーンアップ
// $_GET['path']が存在しない場合は空文字列として扱う (ルートを意味する)
$current_path_param_raw = isset($_GET['path']) ? trim($_GET['path'], '/') : '';

// ディレクトリトラバーサル攻撃対策：../ や ./ を除去
$current_path_param_cleaned = str_replace(['..\\', '../', './', '.\\'], ['', '', '', ''], $current_path_param_raw);

// 連続するスラッシュを単一にする
$current_path_param_cleaned = preg_replace('/\/+/', '/', $current_path_param_cleaned);

// --- 現在の表示パスの決定 ---
$current_display_path_absolute = $base_dir_absolute; // ファイルマネージャーの現在の表示ディレクトリの絶対パス
$current_display_path_relative = ''; // ファイルマネージャーの現在の表示ディレクトリの相対パス（パンくずリスト、URL用）
$valid_path_parts_for_display = []; // パンくずリスト表示用の有効なパスセグメント

// URLのパスセグメントを1つずつ検証し、絶対パスを構築
$temp_path_parts = explode('/', $current_path_param_cleaned);
$current_check_path = $base_dir_absolute;

foreach ($temp_path_parts as $part) {
    if (empty($part)) continue; // 空のセグメントはスキップ

    // パスセグメントが隠しファイル/ディレクトリ（.から始まるもの）の場合、アクセスを許可しない
    // ただし、ルートディレクトリそのものは許可する (.はスキップされるため影響なし)
    if (strpos($part, '.') === 0 && $part !== '.') {
        // これはセキュリティポリシーによる。許可する場合はこのif文を削除
        error_log("WARNING: Attempted access to hidden item: " . $part);
        break; // 以降のパスは無視
    }

    $current_check_path .= DIRECTORY_SEPARATOR . $part;
    $resolved_check_path = realpath($current_check_path); // そのパスが実際に存在するか、かつシンボリックリンクなどを解決

    // 解決されたパスが存在し、かつbase_dir_absoluteの配下にあることを確認
    if ($resolved_check_path !== false && strpos($resolved_check_path, $base_dir_absolute) === 0) {
        $current_display_path_absolute = $resolved_check_path;
        $valid_path_parts_for_display[] = $part;
    } else {
        // このセグメントまたはそれ以降が不正なパス、またはbase_dir_absoluteの外部を指している場合
        // ここでループを終了し、現在の有効なパスまでを$current_display_path_absoluteとする
        break;
    }
}

// パンくずリスト用の相対パスを再構築
if (!empty($valid_path_parts_for_display)) {
    $current_display_path_relative = implode('/', $valid_path_parts_for_display); // スラッシュで結合 (URL用)
}


// --- ヘルパー関数 ---

// ディレクトリを再帰的にコピーするヘルパー関数
function copy_directory($source, $destination) {
    if (!is_dir($source)) {
        return false;
    }
    if (!is_dir($destination)) {
        // ディレクトリが存在しない場合、再帰的に作成を試みる
        if (!mkdir($destination, 0755, true)) {
            error_log("Failed to create directory: " . $destination);
            return false;
        }
    }
    $dir = opendir($source);
    if (!$dir) {
        error_log("Failed to open source directory: " . $source);
        return false;
    }
    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            if (is_dir($source . DIRECTORY_SEPARATOR . $file)) {
                if (!copy_directory($source . DIRECTORY_SEPARATOR . $file, $destination . DIRECTORY_SEPARATOR . $file)) {
                    closedir($dir);
                    return false;
                }
            } else {
                if (!copy($source . DIRECTORY_SEPARATOR . $file, $destination . DIRECTORY_SEPARATOR . $file)) {
                    error_log("Failed to copy file from " . $source . DIRECTORY_SEPARATOR . $file . " to " . $destination . DIRECTORY_SEPARATOR . $file);
                    closedir($dir);
                    return false;
                }
            }
        }
    }
    closedir($dir);
    return true;
}

// ディレクトリを再帰的に削除するヘルパー関数
function delete_directory_recursive($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? delete_directory_recursive("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}


// バイト数を適切な単位に変換する関数
function format_bytes($bytes, $decimals = 2) {
    if ($bytes === 0) return '0 Bytes';
    $k = 1024;
    $dm = $decimals < 0 ? 0 : $decimals;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, $k));
    return round($bytes / pow($k, $i), $dm) . ' ' . $sizes[$i];
}

// パーミッションを数値形式で取得する関数
function get_permissions_octal($path) {
    return substr(sprintf('%o', fileperms($path)), -4);
}


// --- ファイル・フォルダ一覧の取得関数 ---
// これを独立した関数にし、Ajaxリクエストでも利用可能にする
function get_file_list($absolute_path, $base_dir_absolute) {
    $items = [];
    if (is_dir($absolute_path) && is_readable($absolute_path)) {
        $scan_results = scandir($absolute_path);
        if ($scan_results !== false) {
            foreach ($scan_results as $item) {
                if ($item === '.' || $item === '..') continue; // .と..はスキップ

                $item_path_full = $absolute_path . DIRECTORY_SEPARATOR . $item;
                $item_type = is_dir($item_path_full) ? 'folder' : 'file';
                $item_size = $item_type === 'file' ? filesize($item_path_full) : 0;
                $item_modified = filemtime($item_path_full);
                $item_permissions = get_permissions_octal($item_path_full); // パーミッション取得

                // ファイルマネージャーのルートからの相対パスを生成（URL用）
                $relative_path_for_link = str_replace(
                    [$base_dir_absolute, DIRECTORY_SEPARATOR],
                    ['', '/'],
                    $item_path_full
                );
                $relative_path_for_link = ltrim($relative_path_for_link, '/');


                // UI/UX 改善: ファイルタイプに応じたCSSクラスと特殊なタイプを決定
                $item_sub_type = ''; // 例: image, audio, video, pdf, archive, text, unknown
                $item_type_class = ''; // アイコン用CSSクラス
                if ($item_type === 'file') {
                    $file_extension = strtolower(pathinfo($item_path_full, PATHINFO_EXTENSION));
                    switch ($file_extension) {
                        case 'jpg': case 'jpeg': case 'png': case 'gif': case 'webp':
                            $item_sub_type = 'image';
                            $item_type_class = ' file-type-image';
                            break;
                        case 'mp3': case 'wav': case 'ogg': case 'aac':
                            $item_sub_type = 'audio';
                            $item_type_class = ' file-type-audio';
                            break;
                        case 'mp4': case 'avi': case 'mov': case 'webm':
                            $item_sub_type = 'video';
                            $item_type_class = ' file-type-video';
                            break;
                        case 'pdf':
                            $item_sub_type = 'pdf';
                            $item_type_class = ' file-type-pdf';
                            break;
                        case 'zip': case 'tar': case 'gz': case 'rar': case '7z':
                            $item_sub_type = 'archive';
                            $item_type_class = ' file-type-archive';
                            break;
                        case 'txt': case 'log': case 'md': case 'php': case 'html': case 'css':
                        case 'js': case 'json': case 'xml': case 'yml': case 'ini': case 'toml':
                        case 'py': case 'java': case 'c': case 'cpp': case 'sh': case 'sql':
                            $item_sub_type = 'text'; // 明示的にテキストファイルとして扱う
                            $item_type_class = ' file-type-text';
                            break;
                        default:
                            $item_sub_type = 'unknown'; // 不明なタイプはテキストとして編集を試みる
                            $item_type_class = ''; // デフォルトアイコン
                            break;
                    }
                }

                $items[] = [
                    'name' => $item,
                    'type' => $item_type,
                    'sub_type' => $item_sub_type, // 新しく追加
                    'size' => $item_size,
                    'modified' => date('Y-m-d H:i:s', $item_modified),
                    'permissions' => $item_permissions, // パーミッション追加
                    'path_for_link' => $relative_path_for_link,
                    'type_class' => $item_type_class
                ];
            }
            // アイテムをソート（フォルダが先、その後ファイル、どちらも名前順）
            usort($items, function($a, $b) {
                if ($a['type'] === $b['type']) {
                    return strnatcasecmp($a['name'], $b['name']);
                }
                return $a['type'] === 'folder' ? -1 : 1;
            });
        }
    } else {
        error_log("ERROR: Cannot read directory or directory does not exist: " . $absolute_path);
    }
    return $items;
}

// --- ファイル操作処理 ---
if (isset($_POST['action']) || isset($_GET['action'])) {
    $action = $_POST['action'] ?? $_GET['action']; // POSTまたはGETからアクションを取得
    $response = ['status' => 'error', 'message' => '不明なエラーが発生しました。'];

    // アクション対象のディレクトリパスを決定 (Ajaxリクエスト時の現在のパス)
    // $_POST['current_dir'] はJavaScriptから送られる現在のディレクトリ相対パス
    $request_current_dir_relative = $_POST['current_dir'] ?? $_GET['current_dir'] ?? '';

    $target_dir_absolute = $base_dir_absolute; // デフォルトはルートディレクトリ

    // リクエストされたパスを検証し、base_dir_absoluteの範囲内に制限
    $temp_action_path_parts = explode('/', trim($request_current_dir_relative, '/'));
    $current_check_action_path = $base_dir_absolute;

    foreach ($temp_action_path_parts as $part) {
        if (empty($part)) continue;
        // 隠しファイル/ディレクトリへの操作を許可しない (セキュリティポリシーによる)
        if (strpos($part, '.') === 0 && $part !== '.') {
            $response = ['status' => 'error', 'message' => '隠しファイル/ディレクトリへの操作は許可されていません。'];
            echo json_encode($response);
            exit;
        }

        $current_check_action_path .= DIRECTORY_SEPARATOR . $part;
        $resolved_check_action_path = realpath($current_check_action_path);

        if ($resolved_check_action_path !== false && strpos($resolved_check_action_path, $base_dir_absolute) === 0) {
            $target_dir_absolute = $resolved_check_action_path;
        } else {
            // 不正なパス、またはbase_dir_absoluteの外部を指している場合
            $response = ['status' => 'error', 'message' => '無効なパスが指定されました。操作は中断されました。'];
            echo json_encode($response);
            exit;
        }
    }

    // debug_log("Action: " . $action . ", Target Dir Absolute: " . $target_dir_absolute);

    switch ($action) {
        case 'create_folder':
            $folder_name = $_POST['name'] ?? '';
            $new_folder_path = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder_name;

            if (empty($folder_name)) {
                $response['message'] = 'フォルダ名を入力してください。';
            } elseif (file_exists($new_folder_path)) {
                $response['message'] = '同名のフォルダが既に存在します。';
            } elseif (!mkdir($new_folder_path, 0755, true)) {
                $response['message'] = 'フォルダの作成に失敗しました。パーミッションを確認してください。';
            } else {
                $response = ['status' => 'success', 'message' => 'フォルダを作成しました。'];
            }
            break;

        case 'create_file':
            $file_name = $_POST['name'] ?? '';
            $new_file_path = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file_name;

            if (empty($file_name)) {
                $response['message'] = 'ファイル名を入力してください。';
            } elseif (file_exists($new_file_path)) {
                $response['message'] = '同名のファイルが既に存在します。';
            } elseif (file_put_contents($new_file_path, '') === false) {
                $response['message'] = 'ファイルの作成に失敗しました。パーミッションを確認してください。';
            } else {
                $response = ['status' => 'success', 'message' => 'ファイルを作成しました。'];
            }
            break;

        case 'upload_file': // 複数ファイルアップロードに対応
            if (isset($_FILES['upload_files']) && is_array($_FILES['upload_files']['name'])) {
                $uploaded_count = 0;
                $error_upload_messages = [];

                foreach ($_FILES['upload_files']['name'] as $key => $file_name) {
                    if ($_FILES['upload_files']['error'][$key] == UPLOAD_ERR_OK) {
                        $file_tmp_name = $_FILES['upload_files']['tmp_name'][$key];
                        $clean_file_name = basename($file_name); // ファイル名のみを取得し、パスインジェクションを防ぐ
                        $destination_path = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $clean_file_name;

                        if (move_uploaded_file($file_tmp_name, $destination_path)) {
                            $uploaded_count++;
                        } else {
                            $error_upload_messages[] = "「{$clean_file_name}」のアップロードに失敗しました。";
                        }
                    } else {
                        // 個々のファイルのエラーハンドリング
                        $error_code = $_FILES['upload_files']['error'][$key];
                        $error_upload_messages[] = "「{$file_name}」のアップロードエラー: " . $error_code;
                    }
                }

                if ($uploaded_count > 0 && empty($error_upload_messages)) {
                    $response = ['status' => 'success', 'message' => "{$uploaded_count}個のファイルをアップロードしました。"];
                } elseif ($uploaded_count > 0 && !empty($error_upload_messages)) {
                    $response = ['status' => 'warning', 'message' => "{$uploaded_count}個のファイルをアップロードしましたが、一部に失敗しました: " . implode(', ', $error_upload_messages)];
                } else {
                    $response = ['status' => 'error', 'message' => "ファイルのアップロードに失敗しました: " . implode(', ', $error_upload_messages)];
                }
            } else {
                $response['message'] = 'ファイルが選択されていないか、アップロードエラーが発生しました。';
            }
            break;

        case 'delete_item':
            // 複数アイテムに対応
            $item_names = $_POST['names'] ?? []; // namesは配列として受け取る
            $success_count = 0;
            $error_messages = [];

            if (empty($item_names)) {
                $response['message'] = '削除するアイテムが指定されていません。';
                break;
            }

            foreach ($item_names as $item_name) {
                $item_path = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item_name;

                if (!file_exists($item_path)) {
                    $error_messages[] = "「{$item_name}」は見つかりませんでした。";
                    continue;
                } elseif (is_dir($item_path)) {
                    if (!delete_directory_recursive($item_path)) {
                        $error_messages[] = "「{$item_name}」の削除に失敗しました。";
                    } else {
                        $success_count++;
                    }
                } elseif (is_file($item_path)) {
                    if (!unlink($item_path)) {
                        $error_messages[] = "「{$item_name}」の削除に失敗しました。";
                    } else {
                        $success_count++;
                    }
                } else {
                    $error_messages[] = "「{$item_name}」は不明なアイテムタイプです。";
                }
            }

            if ($success_count > 0 && empty($error_messages)) {
                $response = ['status' => 'success', 'message' => "{$success_count}個のアイテムを削除しました。"];
            } elseif ($success_count > 0 && !empty($error_messages)) {
                $response = ['status' => 'warning', 'message' => "{$success_count}個のアイテムを削除しましたが、一部の削除に失敗しました: " . implode(', ', $error_messages)];
            } else {
                $response = ['status' => 'error', 'message' => "アイテムの削除に失敗しました: " . implode(', ', $error_messages)];
            }
            break;

        case 'rename_item':
            $old_name = $_POST['old_name'] ?? '';
            $new_name = $_POST['new_name'] ?? '';

            // セキュリティのため、新しいファイル名にスラッシュが含まれていないか確認
            if (strpos($new_name, '/') !== false || strpos($new_name, '\\') !== false) {
                 $response['message'] = '新しい名前に無効な文字が含まれています。';
                 break;
            }

            $old_path = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $old_name;
            $new_path = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $new_name;

            if (empty($old_name) || empty($new_name) || !file_exists($old_path)) {
                $response['message'] = 'ファイルまたはフォルダが見つかりません。';
            } elseif (file_exists($new_path)) {
                $response['message'] = '同名のファイルまたはフォルダが既に存在します。';
            } elseif (!rename($old_path, $new_path)) {
                $response['message'] = '名前の変更に失敗しました。パーミッションを確認してください。';
            } else {
                $response = ['status' => 'success', 'message' => '名前を変更しました。'];
            }
            break;

        case 'download_file':
            // GETリクエストから現在のパスを取得し、それを基準にファイルを特定
            $request_current_dir_relative_download = $_GET['current_dir'] ?? '';
            $target_dir_absolute_download = $base_dir_absolute;
            $temp_download_path_parts = explode('/', trim($request_current_dir_relative_download, '/'));
            $current_check_download_path = $base_dir_absolute;

            foreach ($temp_download_path_parts as $part) {
                if (empty($part)) continue;
                $current_check_download_path .= DIRECTORY_SEPARATOR . $part;
                $resolved_check_download_path = realpath($current_check_download_path);

                if ($resolved_check_download_path !== false && strpos($resolved_check_download_path, $base_dir_absolute) === 0) {
                    $target_dir_absolute_download = $resolved_check_download_path;
                } else {
                    die("エラー: ダウンロードパスが無効です。"); // 不正なパスは致命的エラーとして扱う
                }
            }

            $file_name = $_GET['name'] ?? '';
            $file_path = rtrim($target_dir_absolute_download, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file_name;

            if (empty($file_name) || !file_exists($file_path) || !is_file($file_path)) {
                $response = ['status' => 'error', 'message' => 'ダウンロードするファイルが見つかりません。'];
            } elseif (strpos($file_path, $base_dir_absolute) !== 0) { // ルートディレクトリ外へのアクセスを防ぐ
                $response = ['status' => 'error', 'message' => 'ダウンロードが許可されていないファイルです。'];
            } else {
                // ファイルをダウンロードさせるヘッダー設定
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($file_name) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($file_path));
                ob_clean(); // 出力バッファをクリア
                flush(); // バッファをフラッシュ
                readfile($file_path); // ファイルを読み込み出力
                exit; // ダウンロード後にスクリプトを終了
            }
            // ダウンロードはAjaxではないので、ここではecho json_encodeしない
            if ($response['status'] === 'error') {
                echo json_encode($response);
            }
            exit; // Ajaxではないダウンロードリクエストでも終了
            break;

        case 'move_item':
        case 'copy_item':
            // 複数アイテムに対応
            $item_names = $_POST['names'] ?? []; // namesは配列として受け取る
            $destination_path_relative = $_POST['destination'] ?? ''; // ターゲットパスは相対パスで送られてくる想定
            $success_count = 0;
            $error_messages = [];
            $action_type_message = ($action === 'move_item') ? '移動' : 'コピー';

            if (empty($item_names) || empty($destination_path_relative) && $destination_path_relative !== '') { // 空文字列はルートとして許可
                $response['message'] = "{$action_type_message}するアイテムまたは移動/コピー先が指定されていません。";
                break;
            }

            // 移動/コピー先の絶対パスを構築し、base_dir_absoluteの範囲内に制限
            $destination_dir_absolute = $base_dir_absolute;
            $temp_dest_parts = explode('/', trim($destination_path_relative, '/'));
            $current_check_dest_path = $base_dir_absolute;
            foreach ($temp_dest_parts as $part) {
                if (empty($part)) continue;
                if (strpos($part, '.') === 0 && $part !== '.') { // 隠しファイル/ディレクトリへの操作を許可しない
                    $response = ['status' => 'error', 'message' => '移動/コピー先に隠しファイル/ディレクトリへの指定は許可されていません。'];
                    echo json_encode($response);
                    exit;
                }
                $current_check_dest_path .= DIRECTORY_SEPARATOR . $part;
                $resolved_dest_path = realpath($current_check_dest_path);

                if ($resolved_dest_path !== false && strpos($resolved_dest_path, $base_dir_absolute) === 0) {
                    $destination_dir_absolute = $resolved_dest_path;
                } else {
                    $response = ['status' => 'error', 'message' => '移動/コピー先のパスが無効です。'];
                    echo json_encode($response);
                    exit;
                }
            }

            foreach ($item_names as $item_name) {
                // 移動/コピー元のパス
                $source_path = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item_name;
                // 移動/コピー先のファイル/フォルダのフルパス
                $target_path = rtrim($destination_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item_name;

                if (!file_exists($source_path)) {
                    $error_messages[] = "「{$item_name}」が見つかりませんでした。";
                    continue;
                } elseif (file_exists($target_path)) {
                    $error_messages[] = "移動/コピー先に同名の「{$item_name}」が既に存在します。";
                    continue;
                } elseif ($source_path === $target_path) {
                    $error_messages[] = "「{$item_name}」は同じ場所へは{$action_type_message}できません。";
                    continue;
                }

                if ($action === 'move_item') {
                    if (!rename($source_path, $target_path)) {
                        $error_messages[] = "「{$item_name}」の移動に失敗しました。";
                    } else {
                        $success_count++;
                    }
                } elseif ($action === 'copy_item') {
                    if (is_dir($source_path)) {
                        if (!copy_directory($source_path, $target_path)) {
                             $error_messages[] = "「{$item_name}」のコピーに失敗しました。";
                        } else {
                            $success_count++;
                        }
                    } elseif (is_file($source_path)) {
                        if (!copy($source_path, $target_path)) {
                            $error_messages[] = "「{$item_name}」のコピーに失敗しました。";
                        } else {
                            $success_count++;
                        }
                    } else {
                        $error_messages[] = "「{$item_name}」は不明なアイテムタイプです。";
                    }
                }
            }

            if ($success_count > 0 && empty($error_messages)) {
                $response = ['status' => 'success', 'message' => "{$success_count}個のアイテムを{$action_type_message}しました。"];
            } elseif ($success_count > 0 && !empty($error_messages)) {
                $response = ['status' => 'warning', 'message' => "{$success_count}個のアイテムを{$action_type_message}しましたが、一部の操作に失敗しました: " . implode(', ', $error_messages)];
            } else {
                $response = ['status' => 'error', 'message' => "アイテムの{$action_type_message}に失敗しました: " . implode(', ', $error_messages)];
            }
            break;
        
        case 'get_file_content': // Ace Editor用のファイル内容取得
            $file_name = $_POST['name'] ?? '';
            $file_path = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file_name;

            if (empty($file_name) || !file_exists($file_path) || !is_file($file_path)) {
                $response = ['status' => 'error', 'message' => 'ファイルが見つかりません。'];
            } elseif (!is_readable($file_path)) {
                 $response = ['status' => 'error', 'message' => 'ファイルを読み込む権限がありません。'];
            } elseif (filesize($file_path) > 1024 * 1024 * 5) { // 5MBを超えるファイルは編集不可とする例
                 $response = ['status' => 'error', 'message' => 'ファイルサイズが大きすぎるため、編集できません。(5MBまで)'];
            } else {
                $content = file_get_contents($file_path);
                if ($content !== false) {
                    $response = ['status' => 'success', 'message' => 'ファイル内容を取得しました。', 'content' => $content];
                } else {
                    $response = ['status' => 'error', 'message' => 'ファイル内容の読み込みに失敗しました。'];
                }
            }
            echo json_encode($response);
            exit; // AJAXリクエストなのでここで終了
            break;

        case 'save_file_content': // Ace Editorで編集した内容の保存
            $file_name = $_POST['name'] ?? '';
            $file_content = $_POST['content'] ?? '';
            $file_path = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file_name;

            if (empty($file_name) || !file_exists($file_path) || !is_file($file_path)) {
                $response = ['status' => 'error', 'message' => '保存するファイルが見つかりません。'];
            } elseif (!is_writable($file_path)) {
                 $response = ['status' => 'error', 'message' => 'ファイルに書き込む権限がありません。'];
            } else {
                if (file_put_contents($file_path, $file_content) !== false) {
                    $response = ['status' => 'success', 'message' => 'ファイルを保存しました。'];
                } else {
                    $response = ['status' => 'error', 'message' => 'ファイルの保存に失敗しました。'];
                }
            }
            echo json_encode($response);
            exit; // AJAXリクエストなのでここで終了
            break;
        
        case 'get_file_list_ajax': // ファイル一覧をAjaxで取得するための新しいアクション
            // $target_dir_absolute は上記の共通処理で計算済み
            $items = get_file_list($target_dir_absolute, $base_dir_absolute);

            // current_path_relative と valid_path_parts_for_display をAjax応答に含める
            // これらは $request_current_dir_relative に基づいて再計算する必要がある
            $ajax_current_path_relative = '';
            $ajax_valid_path_parts_for_display = [];
            $temp_ajax_path_parts = explode('/', trim($request_current_dir_relative, '/'));
            $current_check_ajax_path = $base_dir_absolute;
            foreach ($temp_ajax_path_parts as $part) {
                if (empty($part)) continue;
                 if (strpos($part, '.') === 0 && $part !== '.') { // 隠しファイル/ディレクトリへのアクセスを許可しない
                    // このケースではパスを強制的にルートに戻すか、エラーを返す
                    // 今回はエラーを返してJS側で対処させる
                    $response = ['status' => 'error', 'message' => '隠しファイル/ディレクトリへのアクセスは許可されていません。'];
                    echo json_encode($response);
                    exit;
                }
                $current_check_ajax_path .= DIRECTORY_SEPARATOR . $part;
                $resolved_check_ajax_path = realpath($current_check_ajax_path);
                if ($resolved_check_ajax_path !== false && strpos($resolved_check_ajax_path, $base_dir_absolute) === 0) {
                    $ajax_valid_path_parts_for_display[] = $part;
                } else {
                    break;
                }
            }
            if (!empty($ajax_valid_path_parts_for_display)) {
                $ajax_current_path_relative = implode('/', $ajax_valid_path_parts_for_display);
            }

            $response = [
                'status' => 'success',
                'items' => $items,
                'current_path_relative' => $ajax_current_path_relative,
                'valid_path_parts_for_display' => $ajax_valid_path_parts_for_display
            ];
            echo json_encode($response);
            exit; // Ajaxリクエストなのでここで終了
            break;
            
        case 'get_directory_tree_ajax': // 移動/コピーモーダル用のディレクトリツリー取得
            $tree_path = $_POST['path'] ?? ''; // ツリーのルートとなるパス (相対パス)
            $tree_absolute_path = $base_dir_absolute;

            $temp_tree_path_parts = explode('/', trim($tree_path, '/'));
            $current_check_tree_path = $base_dir_absolute;
            foreach ($temp_tree_path_parts as $part) {
                if (empty($part)) continue;
                if (strpos($part, '.') === 0 && $part !== '.') {
                    $response = ['status' => 'error', 'message' => '隠しファイル/ディレクトリへの指定は許可されていません。'];
                    echo json_encode($response);
                    exit;
                }
                $current_check_tree_path .= DIRECTORY_SEPARATOR . $part;
                $resolved_check_tree_path = realpath($current_check_tree_path);

                if ($resolved_check_tree_path !== false && strpos($resolved_check_tree_path, $base_dir_absolute) === 0) {
                    $tree_absolute_path = $resolved_check_tree_path;
                } else {
                    // 不正なパスはルートにリセットするか、エラーを返す
                    $response = ['status' => 'error', 'message' => '無効なツリーパス。'];
                    echo json_encode($response);
                    exit;
                }
            }

            $directories = get_directory_children($tree_absolute_path, $base_dir_absolute);
            echo json_encode(['status' => 'success', 'directories' => $directories]);
            exit;
            break;

        case 'extract_archive': // 新しいアクション：アーカイブ展開
            $archive_name = $_POST['name'] ?? '';
            $archive_path = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $archive_name;
            $extract_dir = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR); // 同じディレクトリに展開

            if (empty($archive_name) || !file_exists($archive_path) || !is_file($archive_path)) {
                $response['message'] = '展開するアーカイブファイルが見つかりません。';
                break;
            }

            $extension = strtolower(pathinfo($archive_path, PATHINFO_EXTENSION));

            if ($extension === 'zip') {
                $zip = new ZipArchive;
                if ($zip->open($archive_path) === TRUE) {
                    // 展開先のフォルダ名をアーカイブ名から拡張子を除いたものにする
                    $folder_name_for_extract = pathinfo($archive_name, PATHINFO_FILENAME);
                    $full_extract_path = $extract_dir . DIRECTORY_SEPARATOR . $folder_name_for_extract;

                    // 既に同名のフォルダが存在する場合はエラー
                    if (file_exists($full_extract_path) && is_dir($full_extract_path)) {
                         $response['message'] = '同名のフォルダが既に存在します。';
                         $zip->close();
                         break;
                    }

                    if ($zip->extractTo($full_extract_path)) {
                        $response = ['status' => 'success', 'message' => 'Zipファイルを展開しました。'];
                    } else {
                        $response['message'] = 'Zipファイルの展開に失敗しました。パーミッションを確認してください。';
                    }
                    $zip->close();
                } else {
                    $response['message'] = 'Zipファイルを開けませんでした。ファイルが破損している可能性があります。';
                }
            } else {
                $response['message'] = '現在、Zipファイルのみ展開をサポートしています。';
            }
            break;

        case 'change_permissions': // パーミッション変更機能
            $item_name = $_POST['name'] ?? '';
            $new_permissions = $_POST['permissions'] ?? ''; // 例: "755"

            if (empty($item_name) || !preg_match('/^[0-7]{3}$/', $new_permissions)) {
                $response['message'] = '無効なアイテム名またはパーミッションが指定されました。';
                break;
            }

            $item_path = rtrim($target_dir_absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item_name;

            if (!file_exists($item_path)) {
                $response['message'] = '指定されたアイテムが見つかりません。';
                break;
            }

            // octal形式のパーミッション文字列を数値に変換 (chmod関数は八進数を期待するため)
            $mode = octdec($new_permissions);

            if (chmod($item_path, $mode)) {
                $response = ['status' => 'success', 'message' => "「{$item_name}」のパーミッションを {$new_permissions} に変更しました。"];
            } else {
                $response = ['status' => 'error', 'message' => "「{$item_name}」のパーミッション変更に失敗しました。"];
            }
            break;

        default:
            $response['message'] = '無効な操作です。';
            break;
    }

    echo json_encode($response);
    exit;
}

// 初期表示時のファイル一覧取得
$items = get_file_list($current_display_path_absolute, $base_dir_absolute);

// ディレクトリツリー取得用のヘルパー関数 (移動/コピーモーダル用)
function get_directory_children($dir_absolute_path, $base_dir_absolute, $depth = 0) {
    if ($depth > 5) return []; // 深すぎる階層は取得しない (無限ループ防止)
    $children = [];
    if (!is_dir($dir_absolute_path) || !is_readable($dir_absolute_path)) {
        return $children;
    }

    $scan = scandir($dir_absolute_path);
    if ($scan === false) return $children;

    foreach ($scan as $item) {
        if ($item === '.' || $item === '..') continue;
        // 隠しファイル/ディレクトリはツリーに表示しない
        if (strpos($item, '.') === 0 && $item !== '.') continue;

        $item_absolute_path = $dir_absolute_path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($item_absolute_path)) {
            $relative_path_from_root = str_replace(
                [$base_dir_absolute, DIRECTORY_SEPARATOR],
                ['', '/'],
                $item_absolute_path
            );
            $relative_path_from_root = ltrim($relative_path_from_root, '/');

            $children[] = [
                'name' => $item,
                'relative_path' => $relative_path_from_root,
                'children' => get_directory_children($item_absolute_path, $base_dir_absolute, $depth + 1)
            ];
        }
    }
    // ディレクトリ名をアルファベット順にソート
    usort($children, function($a, $b) {
        return strnatcasecmp($a['name'], $b['name']);
    });

    return $children;
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ファイルマネージャー - <?php echo htmlspecialchars($current_user); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ace.js" type="text/javascript" charset="utf-8"></script>
</head>
<body>
    <div class="container">
        <header>
            <h1><i class="fas fa-folder"></i> ファイルマネージャー</h1>
            <div class="header-right">
                <span>ユーザー: **<?php echo htmlspecialchars($current_user); ?>**</span>
                <a href="?logout" class="btn btn-secondary logout-btn"><i class="fas fa-sign-out-alt"></i> ログアウト</a>
            </div>
        </header>

        <nav class="breadcrumb" id="breadcrumb-nav">
            </nav>

        <div class="toolbar">
            <button class="btn btn-primary" id="create-folder-btn"><i class="fas fa-folder-plus"></i> <span>フォルダ作成</span></button>
            <button class="btn btn-primary" id="create-file-btn"><i class="fas fa-file-alt"></i> <span>ファイル作成</span></button>
            <button class="btn btn-success" id="upload-btn"><i class="fas fa-upload"></i> <span>アップロード</span></button>
            
            <button class="btn btn-secondary" id="select-all-btn"><i class="fas fa-check-double"></i> <span>すべて選択</span></button>
            <button class="btn btn-secondary" id="deselect-all-btn"><i class="fas fa-times"></i> <span>選択解除</span></button>

            <button class="btn btn-info" id="edit-btn" disabled><i class="fas fa-edit"></i> <span>編集</span></button>
            <button class="btn btn-info" id="extract-btn" disabled style="display: none;"><i class="fas fa-file-archive"></i> <span>展開</span></button>
            <button class="btn btn-info" id="rename-btn" disabled><i class="fas fa-edit"></i> <span>名前変更</span></button>
            <button class="btn btn-info" id="change-permissions-btn" disabled><i class="fas fa-user-lock"></i> <span>パーミッション</span></button>
            <button class="btn btn-danger" id="delete-btn" disabled><i class="fas fa-trash-alt"></i> <span>削除</span></button>
            <button class="btn btn-secondary" id="move-btn" disabled><i class="fas fa-arrows-alt"></i> <span>移動</span></button>
            <button class="btn btn-secondary" id="copy-btn" disabled><i class="fas fa-copy"></i> <span>コピー</span></button>
        </div>

        <div class="upload-container" style="display: none;">
            <form id="upload-form" action="index.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_file">
                <input type="hidden" name="current_dir" value="<?php echo htmlspecialchars($current_display_path_relative); ?>">
                <input type="file" name="upload_files[]" id="upload-file-input" multiple>
                <div class="drag-drop-area" id="drag-drop-area">
                    ここにファイルをドラッグ＆ドロップ
                    <p>またはファイルを選択</p>
                </div>
                <ul id="file-list-to-upload" class="file-list-to-upload"></ul>
                <div id="upload-progress-container" class="upload-progress-container" style="display: none;">
                    <div id="upload-progress-bar" class="upload-progress-bar"></div>
                    <span id="upload-progress-text" class="upload-progress-text">0%</span>
                </div>
                <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> アップロード実行</button>
                <button type="button" class="btn btn-secondary" id="cancel-upload-btn">キャンセル</button>
            </form>
        </div>

        <div id="message-area" class="message-area" style="display: none;"></div>

        <div class="file-list-container">
            <table class="file-list">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all-checkbox"></th> <th>名前</th>
                        <th>種類</th>
                        <th>サイズ</th>
                        <th>最終更新</th>
                        <th>パーミッション</th> <th>操作</th>
                    </tr>
                </thead>
                <tbody id="file-list-tbody">
                    </tbody>
            </table>
        </div>
    </div>

    <div id="loading-spinner" class="loading-spinner" style="display: none;">
        <i class="fas fa-spinner fa-spin"></i>
        <span>処理中...</span>
    </div>

    <div id="modal" class="modal">
        <div class="modal-content">
            <span class="close-button" data-modal-id="modal">&times;</span>
            <h3 id="modal-title"></h3>
            <div id="modal-body">
                <input type="text" id="modal-input" placeholder="新しい名前">
                <div id="directory-tree-container" class="directory-tree" style="display: none;">
                    <div id="directory-tree-view"></div>
                    <p style="margin-top: 10px;">選択中のパス: <strong id="selected-move-copy-path"></strong></p>
                </div>
                <div id="permissions-container" style="display: none;">
                    <p>現在のパーミッション: <span id="current-permissions-display"></span></p>
                    <div class="permission-row">
                        <label>オーナー:</label>
                        <input type="checkbox" id="perm-owner-read" value="4"> 読
                        <input type="checkbox" id="perm-owner-write" value="2"> 書
                        <input type="checkbox" id="perm-owner-execute" value="1"> 実行
                    </div>
                    <div class="permission-row">
                        <label>グループ:</label>
                        <input type="checkbox" id="perm-group-read" value="4"> 読
                        <input type="checkbox" id="perm-group-write" value="2"> 書
                        <input type="checkbox" id="perm-group-execute" value="1"> 実行
                    </div>
                    <div class="permission-row">
                        <label>その他:</label>
                        <input type="checkbox" id="perm-other-read" value="4"> 読
                        <input type="checkbox" id="perm-other-write" value="2"> 書
                        <input type="checkbox" id="perm-other-execute" value="1"> 実行
                    </div>
                    <p>変更後のパーミッション: <strong id="new-permissions-display">000</strong></p>
                </div>
                <button id="modal-confirm-btn" class="btn btn-primary">確認</button>
            </div>
        </div>
    </div>

    <div id="editor-modal" class="modal">
        <div class="modal-content editor-modal-content">
            <span class="close-button" data-modal-id="editor-modal">&times;</span>
            <h3 id="editor-file-name">ファイル名.txt</h3>
            <div id="editor" style="height: 500px; width: 100%;"></div>
            <div class="editor-buttons">
                <button id="editor-save-btn" class="btn btn-success"><i class="fas fa-save"></i> 保存</button>
                <button id="editor-cancel-btn" class="btn btn-secondary"><i class="fas fa-times"></i> キャンセル</button>
            </div>
        </div>
    </div>

    <script>
        const messageArea = document.getElementById('message-area');
        const uploadContainer = document.querySelector('.upload-container');
        const uploadButton = document.getElementById('upload-btn');
        const cancelUploadButton = document.getElementById('cancel-upload-btn');
        const createFolderButton = document.getElementById('create-folder-btn');
        const createFileButton = document.getElementById('create-file-btn');
        const renameButton = document.getElementById('rename-btn');
        const deleteButton = document.getElementById('delete-btn');
        const moveButton = document.getElementById('move-btn');
        const copyButton = document.getElementById('copy-btn');
        const editButton = document.getElementById('edit-btn');
        const extractButton = document.getElementById('extract-btn');
        const changePermissionsButton = document.getElementById('change-permissions-btn'); // パーミッション変更ボタン
        const fileListTbody = document.getElementById('file-list-tbody');
        const breadcrumbNav = document.getElementById('breadcrumb-nav');
        const loadingSpinner = document.getElementById('loading-spinner');

        // 新しいボタンとチェックボックス
        const selectAllCheckbox = document.getElementById('select-all-checkbox');
        const selectAllButton = document.getElementById('select-all-btn');
        const deselectAllButton = document.getElementById('deselect-all-btn');

        const modal = document.getElementById('modal');
        const modalTitle = document.getElementById('modal-title');
        const modalInput = document.getElementById('modal-input');
        const directoryTreeContainer = document.getElementById('directory-tree-container');
        const directoryTreeView = document.getElementById('directory-tree-view');
        const selectedMoveCopyPath = document.getElementById('selected-move-copy-path');
        const modalConfirmBtn = document.getElementById('modal-confirm-btn');
        const closeButtons = document.querySelectorAll('.close-button');

        const editorModal = document.getElementById('editor-modal');
        const editorFileName = document.getElementById('editor-file-name');
        const editorDiv = document.getElementById('editor');
        const editorSaveBtn = document.getElementById('editor-save-btn');
        const editorCancelBtn = document.getElementById('editor-cancel-btn');

        // 複数ファイルアップロード関連
        const uploadFileInput = document.getElementById('upload-file-input');
        const dragDropArea = document.getElementById('drag-drop-area');
        const fileListToUpload = document.getElementById('file-list-to-upload');
        const uploadProgressBarContainer = document.getElementById('upload-progress-container');
        const uploadProgressBar = document.getElementById('upload-progress-bar');
        const uploadProgressText = document.getElementById('upload-progress-text');
        
        // パーミッション変更関連
        const permissionsContainer = document.getElementById('permissions-container');
        const currentPermissionsDisplay = document.getElementById('current-permissions-display');
        const newPermissionsDisplay = document.getElementById('new-permissions-display');
        const permCheckboxes = {
            owner: {
                read: document.getElementById('perm-owner-read'),
                write: document.getElementById('perm-owner-write'),
                execute: document.getElementById('perm-owner-execute')
            },
            group: {
                read: document.getElementById('perm-group-read'),
                write: document.getElementById('perm-group-write'),
                execute: document.getElementById('perm-group-execute')
            },
            other: {
                read: document.getElementById('perm-other-read'),
                write: document.getElementById('perm-other-write'),
                execute: document.getElementById('perm-other-execute')
            }
        };

        let currentAction = '';
        let aceEditor = null;
        let currentEditingFile = '';
        let currentDirectoryRelativePath = '<?php echo htmlspecialchars($current_display_path_relative); ?>';
        let currentValidPathParts = <?php echo json_encode($valid_path_parts_for_display); ?>;
        let selectedMoveCopyDirectory = '';
        let filesToUpload = []; // アップロードキュー用の配列

        // --- 初期化処理 ---
        document.addEventListener('DOMContentLoaded', () => {
            renderFileList(<?php echo json_encode($items); ?>);
            updateBreadcrumbs();
            updateToolbarButtons();
            modal.style.display = 'none';
            editorModal.style.display = 'none';
        });

        // --- UI/UX: メッセージ表示関数（アニメーション対応）---
        function showMessage(message, type = 'info') {
            messageArea.textContent = message;
            messageArea.className = 'message-area ' + type + ' show';
            messageArea.style.display = 'block';

            if (messageArea.hideTimeout) {
                clearTimeout(messageArea.hideTimeout);
            }

            messageArea.hideTimeout = setTimeout(() => {
                messageArea.classList.remove('show');
                setTimeout(() => {
                    messageArea.style.display = 'none';
                }, 500);
            }, 5000);
        }

        // --- UI/UX: ローディングスピナー表示/非表示 ---
        function showSpinner() {
            loadingSpinner.style.display = 'flex';
        }

        function hideSpinner() {
            loadingSpinner.style.display = 'none';
        }

        // --- UI/UX: 選択状態とツールバーボタンの更新 ---
        function updateToolbarButtons() {
            let selectedCheckboxes = document.querySelectorAll('input[name="selected_items[]"]:checked');
            let selectedCount = selectedCheckboxes.length;
            
            // 選択された行のハイライト
            document.querySelectorAll('.file-list tbody tr').forEach(row => {
                const checkbox = row.querySelector('input[name="selected_items[]"]');
                if (checkbox && checkbox.checked) {
                    row.classList.add('selected');
                } else {
                    row.classList.remove('selected');
                }
            });

            // 「すべて選択」チェックボックスの状態を同期
            const allCheckboxes = document.querySelectorAll('input[name="selected_items[]"]');
            selectAllCheckbox.checked = selectedCount > 0 && selectedCount === allCheckboxes.length;
            selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < allCheckboxes.length;
            if (allCheckboxes.length === 0) {
                 selectAllCheckbox.disabled = true;
            } else {
                 selectAllCheckbox.disabled = false;
            }

            // 各ボタンの有効/無効設定
            renameButton.disabled = selectedCount !== 1; // 1つだけ選択されている場合のみ有効
            editButton.disabled = selectedCount !== 1;
            extractButton.disabled = selectedCount !== 1;
            changePermissionsButton.disabled = selectedCount !== 1; // パーミッション変更も1つだけ選択

            deleteButton.disabled = selectedCount === 0; // 0個の場合は無効
            moveButton.disabled = selectedCount === 0;
            copyButton.disabled = selectedCount === 0;

            // 編集と展開ボタンの追加チェック
            if (selectedCount === 1) {
                const selectedItem = selectedCheckboxes[0];
                const selectedType = selectedItem.dataset.type;
                const selectedSubType = selectedItem.dataset.subtype;

                if (selectedType === 'file') {
                    // 編集可能なファイルタイプか判定
                    const isEditable = !['image', 'audio', 'video', 'pdf', 'archive'].includes(selectedSubType);
                    editButton.disabled = !isEditable;

                    // zipファイルの場合に展開ボタンを表示
                    if (selectedSubType === 'archive') {
                        extractButton.style.display = '';
                        extractButton.disabled = false;
                    } else {
                        extractButton.style.display = 'none';
                        extractButton.disabled = true;
                    }
                } else { // フォルダが選択されている場合
                    editButton.disabled = true;
                    extractButton.style.display = 'none';
                    extractButton.disabled = true;
                }
            } else {
                editButton.disabled = true;
                extractButton.style.display = 'none';
                extractButton.disabled = true;
            }
        }

        // --- ファイルリストのレンダリング ---
        function renderFileList(items) {
            fileListTbody.innerHTML = '';

            if (items.length === 0) {
                fileListTbody.innerHTML = '<tr><td colspan="7">このフォルダにはアイテムがありません。</td></tr>'; // 列数修正
                selectAllCheckbox.disabled = true; 
                return;
            } else {
                 selectAllCheckbox.disabled = false; 
            }

            items.forEach(item => {
                const row = document.createElement('tr');
                row.dataset.name = item.name;
                row.dataset.type = item.type;
                row.dataset.subtype = item.sub_type;
                row.dataset.permissions = item.permissions; // パーミッションを追加

                let sizeHtml = item.type === 'file' ? formatBytes(item.size) : '-';
                let itemHtml;

                if (item.type === 'folder') {
                    itemHtml = `<a href="#" class="folder-link" data-path="${item.path_for_link}"><i class="fas fa-folder folder-icon"></i> ${escapeHtml(item.name)}</a>`;
                } else {
                    let fileIconClass = 'fas fa-file file-icon';
                    switch (item.sub_type) {
                        case 'image': fileIconClass = 'fas fa-image file-icon'; break;
                        case 'audio': fileIconClass = 'fas fa-music file-icon'; break;
                        case 'video': fileIconClass = 'fas fa-video file-icon'; break;
                        case 'pdf': fileIconClass = 'fas fa-file-pdf file-icon'; break;
                        case 'archive': fileIconClass = 'fas fa-file-archive file-icon'; break;
                        case 'text': fileIconClass = 'fas fa-file-code file-icon'; break;
                    }
                    itemHtml = `<i class="${fileIconClass}${escapeHtml(item.type_class)}"></i> ${escapeHtml(item.name)}`;
                }

                let downloadHtml = '';
                if (item.type === 'file') {
                    downloadHtml = `<a href="index.php?action=download_file&name=${encodeURIComponent(item.name)}&current_dir=${encodeURIComponent(currentDirectoryRelativePath)}" class="btn btn-sm btn-action download-btn" download title="ダウンロード"><i class="fas fa-download"></i></a>`;
                }

                row.innerHTML = `
                    <td><input type="checkbox" name="selected_items[]" value="${escapeHtml(item.name)}" data-type="${escapeHtml(item.type)}" data-subtype="${escapeHtml(item.sub_type)}" data-permissions="${escapeHtml(item.permissions)}"></td>
                    <td>${itemHtml}</td>
                    <td>${escapeHtml(item.type)}</td>
                    <td>${sizeHtml}</td>
                    <td>${escapeHtml(item.modified)}</td>
                    <td>${escapeHtml(item.permissions)}</td>
                    <td>${downloadHtml}</td>
                `;
                fileListTbody.appendChild(row);
            });

            attachEventListenersToRows();
            updateToolbarButtons();
        }

        // --- パンくずリストの更新 ---
        function updateBreadcrumbs() {
            breadcrumbNav.innerHTML = '';

            let rootLink = document.createElement('a');
            rootLink.href = "#";
            rootLink.classList.add('folder-link');
            rootLink.dataset.path = '';
            rootLink.innerHTML = '<i class="fas fa-home"></i> ルート';
            breadcrumbNav.appendChild(rootLink);

            let pathSoFar = '';
            currentValidPathParts.forEach(part => {
                pathSoFar += (pathSoFar === '' ? '' : '/') + part;
                let separator = document.createElement('span');
                separator.textContent = ' > ';
                breadcrumbNav.appendChild(separator);

                let partLink = document.createElement('a');
                partLink.href = "#";
                partLink.classList.add('folder-link');
                partLink.dataset.path = pathSoFar;
                partLink.textContent = part;
                breadcrumbNav.appendChild(partLink);
            });

            breadcrumbNav.querySelectorAll('.folder-link').forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    const newPath = e.currentTarget.dataset.path;
                    fetchFileList(newPath);
                });
            });
        }

        // --- 行クリックとチェックボックスの連動 ---
        function attachEventListenersToRows() {
            document.querySelectorAll('.file-list tbody tr').forEach(row => {
                // コンテキストメニュー（右クリック）イベント
                row.addEventListener('contextmenu', function(event) {
                    event.preventDefault(); // デフォルトの右クリックメニューを抑制
                    const checkbox = this.querySelector('input[name="selected_items[]"]');
                    
                    // クリックされたアイテムが選択されていない場合、そのアイテムのみを選択状態にする
                    if (!checkbox.checked) {
                        document.querySelectorAll('input[name="selected_items[]"]').forEach(cb => cb.checked = false);
                        checkbox.checked = true;
                        updateToolbarButtons();
                    }
                    
                    showContextMenu(event.clientX, event.clientY, checkbox.value, checkbox.dataset.type, checkbox.dataset.subtype, checkbox.dataset.permissions);
                });

                row.addEventListener('click', function(event) {
                    if (event.target.tagName === 'INPUT' || event.target.tagName === 'A' || event.target.closest('a')) {
                        return;
                    }
                    const checkbox = this.querySelector('input[name="selected_items[]"]');
                    if (checkbox) {
                        checkbox.checked = !checkbox.checked; // クリックでチェック状態をトグル
                        updateToolbarButtons();
                    }
                });
                const folderLink = row.querySelector('.folder-link');
                if (folderLink) {
                    folderLink.addEventListener('click', (e) => {
                        e.preventDefault();
                        const newPath = e.currentTarget.dataset.path;
                        fetchFileList(newPath);
                    });
                }
            });
            // チェックボックス自体の変更イベント
            document.querySelectorAll('input[name="selected_items[]"]').forEach(checkbox => {
                checkbox.addEventListener('change', updateToolbarButtons);
            });
        }

        // --- コンテキストメニューの表示 ---
        let contextMenu = null;
        function showContextMenu(x, y, itemName, itemType, itemSubType, itemPermissions) {
            if (contextMenu) {
                contextMenu.remove();
            }

            contextMenu = document.createElement('ul');
            contextMenu.classList.add('context-menu');
            contextMenu.style.left = `${x}px`;
            contextMenu.style.top = `${y}px`;

            const actions = [
                { text: '開く (フォルダ)', icon: 'fas fa-folder-open', action: 'open_folder', type: 'folder' },
                { text: '編集', icon: 'fas fa-edit', action: 'edit_item', type: 'file', editable: true },
                { text: '展開', icon: 'fas fa-file-archive', action: 'extract_item', type: 'file', subtype: 'archive' },
                { text: '名前変更', icon: 'fas fa-edit', action: 'rename_item' },
                { text: 'パーミッション変更', icon: 'fas fa-user-lock', action: 'change_permissions' },
                { text: '削除', icon: 'fas fa-trash-alt', action: 'delete_item' },
                { text: '移動', icon: 'fas fa-arrows-alt', action: 'move_item' },
                { text: 'コピー', icon: 'fas fa-copy', action: 'copy_item' },
                { text: 'ダウンロード', icon: 'fas fa-download', action: 'download_item', type: 'file' }
            ];

            actions.forEach(item => {
                let li = document.createElement('li');
                let isDisabled = false;

                if (item.type && item.type !== itemType) {
                    isDisabled = true;
                }
                if (item.editable && (itemType !== 'file' || ['image', 'audio', 'video', 'pdf', 'archive'].includes(itemSubType))) {
                    isDisabled = true;
                }
                if (item.subtype && item.subtype !== itemSubType) {
                    isDisabled = true;
                }
                if (item.action === 'open_folder' && itemType !== 'folder') {
                    isDisabled = true;
                }

                if (isDisabled) {
                    li.classList.add('disabled');
                }

                li.innerHTML = `<i class="${item.icon}"></i> ${item.text}`;
                li.addEventListener('click', () => {
                    if (isDisabled) return;
                    hideContextMenu();
                    handleContextAction(item.action, itemName, itemType, itemSubType, itemPermissions);
                });
                contextMenu.appendChild(li);
            });

            document.body.appendChild(contextMenu);

            // メニュー外をクリックで閉じる
            document.addEventListener('click', hideContextMenuOutside, { once: true });
        }

        function hideContextMenu() {
            if (contextMenu) {
                contextMenu.remove();
                contextMenu = null;
            }
            document.removeEventListener('click', hideContextMenuOutside);
        }

        function hideContextMenuOutside(event) {
            if (contextMenu && !contextMenu.contains(event.target)) {
                hideContextMenu();
            }
        }

        function handleContextAction(action, itemName, itemType, itemSubType, itemPermissions) {
            // 単一選択操作であることを確認
            const selectedCheckbox = document.querySelector(`input[name="selected_items[]"][value="${itemName}"]`);
            if (!selectedCheckbox) return; // 選択されていない場合は何もしない

            // 他の選択を解除し、このアイテムのみを選択状態にする
            document.querySelectorAll('input[name="selected_items[]"]').forEach(cb => cb.checked = false);
            selectedCheckbox.checked = true;
            updateToolbarButtons(); // ツールバーボタンの状態を更新

            switch (action) {
                case 'open_folder':
                    if (itemType === 'folder') {
                        fetchFileList(selectedCheckbox.dataset.path);
                    }
                    break;
                case 'edit_item':
                    editButton.click(); // 既存の編集ボタンのロジックをトリガー
                    break;
                case 'extract_item':
                    extractButton.click(); // 既存の展開ボタンのロジックをトリガー
                    break;
                case 'rename_item':
                    renameButton.click(); // 既存の名前変更ボタンのロジックをトリガー
                    break;
                case 'change_permissions':
                    changePermissionsButton.click(); // 既存のパーミッション変更ボタンのロジックをトリガー
                    break;
                case 'delete_item':
                    deleteButton.click(); // 既存の削除ボタンのロジックをトリガー
                    break;
                case 'move_item':
                    moveButton.click(); // 既存の移動ボタンのロジックをトリガー
                    break;
                case 'copy_item':
                    copyButton.click(); // 既存のコピーボタンのロジックをトリガー
                    break;
                case 'download_item':
                    // 直接ダウンロードリンクを生成してクリック
                    const downloadLink = document.createElement('a');
                    downloadLink.href = `index.php?action=download_file&name=${encodeURIComponent(itemName)}&current_dir=${encodeURIComponent(currentDirectoryRelativePath)}`;
                    downloadLink.download = itemName;
                    document.body.appendChild(downloadLink);
                    downloadLink.click();
                    document.body.removeChild(downloadLink);
                    break;
            }
        }


        // --- モーダル表示/非表示関数 ---
        function showModal(title, inputPlaceholder, confirmButtonText, actionType, options = {}) {
            modalTitle.textContent = title;
            modalInput.placeholder = inputPlaceholder;
            modalConfirmBtn.textContent = confirmButtonText;
            currentAction = actionType;

            // 各コンテナの表示状態をリセット
            modalInput.style.display = 'none';
            directoryTreeContainer.style.display = 'none';
            permissionsContainer.style.display = 'none';

            if (options.showInput) {
                modalInput.style.display = 'block';
            }
            if (options.showDirectoryTree) {
                directoryTreeContainer.style.display = 'block';
                selectedMoveCopyDirectory = '';
                selectedMoveCopyPath.textContent = '選択されていません';
                fetchDirectoryTree();
            }
            if (options.showPermissions) {
                permissionsContainer.style.display = 'block';
                const selectedCheckbox = document.querySelector('input[name="selected_items[]"]:checked');
                if (selectedCheckbox) {
                    const currentPerms = selectedCheckbox.dataset.permissions;
                    currentPermissionsDisplay.textContent = currentPerms;
                    setPermissionsCheckboxes(currentPerms);
                    updateNewPermissionsDisplay(); // 初期表示で新しいパーミッションも更新
                }
            }

            modal.style.display = 'flex';
            if (options.showInput) {
                modalInput.focus();
            }
        }

        function hideModal(modalElement) {
            modalElement.style.display = 'none';
            if (modalElement === modal) {
                modalInput.value = '';
                directoryTreeView.innerHTML = '';
                // パーミッションチェックボックスをリセット
                Object.values(permCheckboxes).forEach(group => {
                    Object.values(group).forEach(checkbox => checkbox.checked = false);
                });
                newPermissionsDisplay.textContent = '000';
            } else if (modalElement === editorModal) {
                if (aceEditor) {
                    aceEditor.destroy();
                    aceEditor = null;
                    editorDiv.innerHTML = '';
                }
            }
        }

        // --- イベントリスナー ---
        uploadButton.addEventListener('click', () => {
            uploadContainer.style.display = 'block';
            fileListToUpload.innerHTML = ''; // リストをクリア
            filesToUpload = []; // アップロードキューをクリア
        });

        cancelUploadButton.addEventListener('click', () => {
            uploadContainer.style.display = 'none';
            document.getElementById('upload-form').reset();
            fileListToUpload.innerHTML = '';
            filesToUpload = [];
            uploadProgressBarContainer.style.display = 'none'; // プログレスバー非表示
            uploadProgressBar.style.width = '0%';
            uploadProgressText.textContent = '0%';
        });

        // 複数ファイル選択
        uploadFileInput.addEventListener('change', (e) => {
            filesToUpload = Array.from(e.target.files);
            renderFilesToUpload();
        });

        // ドラッグ＆ドロップ
        dragDropArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            dragDropArea.classList.add('drag-over');
        });

        dragDropArea.addEventListener('dragleave', () => {
            dragDropArea.classList.remove('drag-over');
        });

        dragDropArea.addEventListener('drop', (e) => {
            e.preventDefault();
            dragDropArea.classList.remove('drag-over');
            filesToUpload = Array.from(e.dataTransfer.files);
            renderFilesToUpload();
            // input要素にもファイルをセット (submit時に必要)
            const dataTransfer = new DataTransfer();
            filesToUpload.forEach(file => dataTransfer.items.add(file));
            uploadFileInput.files = dataTransfer.files;
        });

        function renderFilesToUpload() {
            fileListToUpload.innerHTML = '';
            if (filesToUpload.length === 0) {
                fileListToUpload.innerHTML = '<li>ファイルが選択されていません。</li>';
                return;
            }
            filesToUpload.forEach((file, index) => {
                const li = document.createElement('li');
                li.innerHTML = `
                    <span><i class="fas fa-file"></i> ${escapeHtml(file.name)} (${formatBytes(file.size)})</span>
                    <button type="button" class="btn btn-sm btn-action remove-file-btn" data-index="${index}"><i class="fas fa-times"></i></button>
                `;
                fileListToUpload.appendChild(li);
            });

            document.querySelectorAll('.remove-file-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const indexToRemove = parseInt(e.target.dataset.index || e.target.closest('button').dataset.index);
                    filesToUpload.splice(indexToRemove, 1);
                    renderFilesToUpload();

                    // input要素のfilesからも削除
                    const dataTransfer = new DataTransfer();
                    filesToUpload.forEach(file => dataTransfer.items.add(file));
                    uploadFileInput.files = dataTransfer.files;
                });
            });
        }


        document.getElementById('upload-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (filesToUpload.length === 0) {
                showMessage('アップロードするファイルを選択してください。', 'warning');
                return;
            }

            const formData = new FormData(this);
            formData.append('current_dir', currentDirectoryRelativePath);
            // upload_files[] は input[type="file"][multiple] で自動的に追加される

            // プログレスバーを表示
            uploadProgressBarContainer.style.display = 'block';
            uploadProgressBar.style.width = '0%';
            uploadProgressText.textContent = '0%';

            showSpinner(); // 全体スピナーも表示

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'index.php', true);

            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    const percent = Math.round((event.loaded / event.total) * 100);
                    uploadProgressBar.style.width = `${percent}%`;
                    uploadProgressText.textContent = `${percent}%`;
                }
            });

            xhr.addEventListener('load', () => {
                hideSpinner();
                uploadProgressBarContainer.style.display = 'none'; // プログレスバー非表示
                const result = JSON.parse(xhr.responseText);
                showMessage(result.message, result.status);
                if (result.status === 'success' || result.status === 'warning') {
                    uploadContainer.style.display = 'none';
                    document.getElementById('upload-form').reset();
                    fileListToUpload.innerHTML = '';
                    filesToUpload = [];
                    fetchFileList(currentDirectoryRelativePath);
                }
            });

            xhr.addEventListener('error', () => {
                hideSpinner();
                uploadProgressBarContainer.style.display = 'none';
                showMessage('ファイルのアップロード中にネットワークエラーが発生しました。', 'error');
            });

            xhr.send(formData);
        });


        createFolderButton.addEventListener('click', () => {
            showModal('新しいフォルダを作成', 'フォルダ名', '作成', 'create_folder', { showInput: true });
        });

        createFileButton.addEventListener('click', () => {
            showModal('新しいファイルを作成', 'ファイル名 (例: index.html)', '作成', 'create_file', { showInput: true });
        });

        // 「すべて選択」ボタン
        selectAllButton.addEventListener('click', () => {
            document.querySelectorAll('input[name="selected_items[]"]').forEach(checkbox => {
                checkbox.checked = true;
            });
            updateToolbarButtons();
        });

        // 「選択解除」ボタン
        deselectAllButton.addEventListener('click', () => {
            document.querySelectorAll('input[name="selected_items[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            updateToolbarButtons();
        });

        // ヘッダーの全選択チェックボックス
        selectAllCheckbox.addEventListener('change', (e) => {
            document.querySelectorAll('input[name="selected_items[]"]').forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
            updateToolbarButtons();
        });

        renameButton.addEventListener('click', () => {
            const selectedCheckbox = document.querySelector('input[name="selected_items[]"]:checked');
            if (selectedCheckbox) {
                const oldName = selectedCheckbox.value;
                showModal('名前の変更', '新しい名前', '変更', 'rename_item', { showInput: true });
                modalInput.value = oldName;
            }
        });

        deleteButton.addEventListener('click', () => {
            const selectedCheckboxes = document.querySelectorAll('input[name="selected_items[]"]:checked');
            const selectedNames = Array.from(selectedCheckboxes).map(cb => cb.value);

            if (selectedNames.length > 0) {
                let confirmMessage = `本当に選択された${selectedNames.length}個のアイテムを削除しますか？`;
                const containsFolder = Array.from(selectedCheckboxes).some(cb => cb.dataset.type === 'folder');
                if (containsFolder) {
                    confirmMessage += '\n(フォルダ内のファイルも全て削除されます)';
                }

                if (confirm(confirmMessage)) {
                    performAction('delete_item', { names: selectedNames });
                }
            } else {
                showMessage('削除するアイテムを選択してください。');
            }
        });

        moveButton.addEventListener('click', () => {
            const selectedCheckboxes = document.querySelectorAll('input[name="selected_items[]"]:checked');
            if (selectedCheckboxes.length > 0) {
                showModal('アイテムを移動', '', '移動', 'move_item', { showDirectoryTree: true });
            }
        });

        copyButton.addEventListener('click', () => {
            const selectedCheckboxes = document.querySelectorAll('input[name="selected_items[]"]:checked');
            if (selectedCheckboxes.length > 0) {
                showModal('アイテムをコピー', '', 'コピー', 'copy_item', { showDirectoryTree: true });
            }
        });

        editButton.addEventListener('click', () => {
            const selectedCheckbox = document.querySelector('input[name="selected_items[]"]:checked');
            if (selectedCheckbox && selectedCheckbox.dataset.type === 'file' && !['image', 'audio', 'video', 'pdf', 'archive'].includes(selectedCheckbox.dataset.subtype)) {
                currentEditingFile = selectedCheckbox.value;
                editorFileName.textContent = currentEditingFile;
                editorModal.style.display = 'flex';
                initializeAceEditor(currentEditingFile);
                fetchFileContent(currentEditingFile);
            } else {
                showMessage('編集できないファイルタイプです。', 'info');
            }
        });

        extractButton.addEventListener('click', () => {
            const selectedCheckbox = document.querySelector('input[name="selected_items[]"]:checked');
            if (selectedCheckbox && selectedCheckbox.dataset.type === 'file' && selectedCheckbox.dataset.subtype === 'archive') {
                const archiveName = selectedCheckbox.value;
                if (confirm(`本当に「${archiveName}」を展開しますか？`)) {
                    performAction('extract_archive', { name: archiveName });
                }
            } else {
                showMessage('展開するアーカイブファイルを選択してください。', 'info');
            }
        });

        changePermissionsButton.addEventListener('click', () => {
            const selectedCheckbox = document.querySelector('input[name="selected_items[]"]:checked');
            if (selectedCheckbox) {
                showModal('パーミッション変更', '', '変更', 'change_permissions', { showPermissions: true });
                // モーダル表示時にパーミッションの状態を設定
                setPermissionsCheckboxes(selectedCheckbox.dataset.permissions);
            } else {
                showMessage('パーミッションを変更するアイテムを選択してください。', 'info');
            }
        });

        closeButtons.forEach(button => {
            button.addEventListener('click', () => {
                const modalToClose = document.getElementById(button.dataset.modalId);
                if (modalToClose) {
                    hideModal(modalToClose);
                }
            });
        });
        window.addEventListener('click', (event) => {
            if (event.target === modal) {
                hideModal(modal);
            } else if (event.target === editorModal) {
                hideModal(editorModal);
            }
            // コンテキストメニューを閉じる
            if (contextMenu && !contextMenu.contains(event.target)) {
                hideContextMenu();
            }
        });


        modalConfirmBtn.addEventListener('click', () => {
            const name = modalInput.value.trim();
            const selectedCheckboxes = document.querySelectorAll('input[name="selected_items[]"]:checked');
            const selectedNames = Array.from(selectedCheckboxes).map(cb => cb.value);

            if (currentAction === 'create_folder' || currentAction === 'create_file') {
                if (name) {
                    performAction(currentAction, { name: name });
                } else {
                    showMessage('名前を入力してください。');
                }
            } else if (currentAction === 'rename_item') {
                if (selectedNames.length === 1 && name) {
                    performAction(currentAction, { old_name: selectedNames[0], new_name: name });
                } else {
                    showMessage('新しい名前を入力してください。');
                }
            } else if (currentAction === 'move_item' || currentAction === 'copy_item') {
                if (selectedNames.length > 0 && selectedMoveCopyDirectory !== null) {
                    // 移動/コピー先に現在のディレクトリを選択した場合は、操作を拒否
                    if (selectedMoveCopyDirectory === currentDirectoryRelativePath) {
                        showMessage('同じ場所へは移動/コピーできません。', 'error');
                        hideModal(modal);
                        return;
                    }
                    performAction(currentAction, { names: selectedNames, destination: selectedMoveCopyDirectory });
                } else {
                    showMessage('移動/コピー元と移動/コピー先を選択してください。');
                }
            } else if (currentAction === 'change_permissions') {
                if (selectedNames.length === 1) {
                    const newPerms = newPermissionsDisplay.textContent;
                    performAction(currentAction, { name: selectedNames[0], permissions: newPerms });
                } else {
                    showMessage('パーミッションを変更するアイテムを選択してください。');
                }
            }
            hideModal(modal);
        });


        // --- Ace Editor 関連ロジック ---
        editorCancelBtn.addEventListener('click', () => {
            hideModal(editorModal);
        });

        editorSaveBtn.addEventListener('click', () => {
            if (aceEditor && currentEditingFile) {
                const content = aceEditor.getValue();
                saveFileContent(currentEditingFile, content);
            }
        });

        function initializeAceEditor(fileName) {
            if (aceEditor) {
                aceEditor.destroy();
                aceEditor = null;
                editorDiv.innerHTML = '';
            }

            aceEditor = ace.edit(editorDiv);
            
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                aceEditor.setTheme("ace/theme/dracula");
            } else {
                aceEditor.setTheme("ace/theme/chrome");
            }

            aceEditor.session.setUseWrapMode(true);
            aceEditor.session.setTabSize(4);
            aceEditor.session.setUseSoftTabs(true);

            const fileExtension = fileName.split('.').pop().toLowerCase();
            let mode = 'ace/mode/plain_text';

            switch (fileExtension) {
                case 'js': mode = 'ace/mode/javascript'; break;
                case 'html': case 'htm': mode = 'ace/mode/html'; break;
                case 'css': mode = 'ace/mode/css'; break;
                case 'php': mode = 'ace/mode/php'; break;
                case 'json': mode = 'ace/mode/json'; break;
                case 'xml': mode = 'ace/mode/xml'; break;
                case 'md': mode = 'ace/mode/markdown'; break;
                case 'py': mode = 'ace/mode/python'; break;
                case 'java': mode = 'ace/mode/java'; break;
                case 'c': case 'cpp': mode = 'ace/mode/c_cpp'; break;
                case 'sh': mode = 'ace/mode/sh'; break;
                case 'sql': mode = 'ace/mode/sql'; break;
                case 'vue': mode = 'ace/mode/vue'; break;
                case 'scss': mode = 'ace/mode/scss'; break;
                case 'yaml': case 'yml': mode = 'ace/mode/yaml'; break;
                case 'go': mode = 'ace/mode/golang'; break;
                case 'rb': mode = 'ace/mode/ruby'; break;
                case 'swift': mode = 'ace/mode/swift'; break;
                case 'ts': mode = 'ace/mode/typescript'; break;
                case 'tsx': mode = 'ace/mode/tsx'; break;
                case 'jsx': mode = 'ace/mode/jsx'; break;
                case 'toml': mode = 'ace/mode/toml'; break;
                case 'ini': mode = 'ace/mode/ini'; break;
                case 'conf': mode = 'ace/mode/conf'; break;
                case 'htaccess':
                case 'gitignore':
                case 'editorconfig':
                case 'npmignore':
                case 'dockerignore':
                case 'dockerfile':
                case 'ps1':
                case 'cmd':
                case 'bat':
                case 'vbs':
                case 'csv':
                case 'tsv':
                case 'text':
                case 'log':
                case '':
                    mode = 'ace/mode/plain_text';
                    break;
            }
            aceEditor.session.setMode(mode);
            aceEditor.resize();
        }

        function fetchFileContent(fileName) {
            const formData = new FormData();
            formData.append('action', 'get_file_content');
            formData.append('name', fileName);
            formData.append('current_dir', currentDirectoryRelativePath);

            showSpinner();
            fetch('index.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(result => {
                hideSpinner();
                if (result.status === 'success') {
                    aceEditor.setValue(result.content, -1);
                    aceEditor.focus();
                } else {
                    showMessage(result.message, 'error');
                    hideModal(editorModal);
                }
            })
            .catch(error => {
                hideSpinner();
                console.error('Error fetching file content:', error);
                showMessage('ファイル内容の取得中にエラーが発生しました。', 'error');
                hideModal(editorModal);
            });
        }

        function saveFileContent(fileName, content) {
            const formData = new FormData();
            formData.append('action', 'save_file_content');
            formData.append('name', fileName);
            formData.append('content', content);
            formData.append('current_dir', currentDirectoryRelativePath);

            showSpinner();
            fetch('index.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(result => {
                hideSpinner();
                showMessage(result.message, result.status);
                if (result.status === 'success') {
                    hideModal(editorModal);
                    fetchFileList(currentDirectoryRelativePath);
                }
            })
            .catch(error => {
                hideSpinner();
                console.error('Error saving file content:', error);
                showMessage('ファイル保存中にエラーが発生しました。', 'error');
            });
        }


        // --- パーミッション変更機能のロジック ---
        Object.values(permCheckboxes).forEach(group => {
            Object.values(group).forEach(checkbox => {
                checkbox.addEventListener('change', updateNewPermissionsDisplay);
            });
        });

        function setPermissionsCheckboxes(octalPerms) {
            // 例: "0755" -> "755" (最初の0は無視)
            const perms = octalPerms.substring(octalPerms.length - 3).split('').map(Number); // 各桁を数値に変換

            const groups = ['owner', 'group', 'other'];
            groups.forEach((groupName, index) => {
                const p = perms[index];
                permCheckboxes[groupName].read.checked = (p & 4) !== 0; // 読み取り (4)
                permCheckboxes[groupName].write.checked = (p & 2) !== 0; // 書き込み (2)
                permCheckboxes[groupName].execute.checked = (p & 1) !== 0; // 実行 (1)
            });
        }

        function updateNewPermissionsDisplay() {
            let ownerPerm = 0;
            if (permCheckboxes.owner.read.checked) ownerPerm += 4;
            if (permCheckboxes.owner.write.checked) ownerPerm += 2;
            if (permCheckboxes.owner.execute.checked) ownerPerm += 1;

            let groupPerm = 0;
            if (permCheckboxes.group.read.checked) groupPerm += 4;
            if (permCheckboxes.group.write.checked) groupPerm += 2;
            if (permCheckboxes.group.execute.checked) groupPerm += 1;

            let otherPerm = 0;
            if (permCheckboxes.other.read.checked) otherPerm += 4;
            if (permCheckboxes.other.write.checked) otherPerm += 2;
            if (permCheckboxes.other.execute.checked) otherPerm += 1;

            newPermissionsDisplay.textContent = `${ownerPerm}${groupPerm}${otherPerm}`;
        }


        // --- AJAXリクエスト実行関数 (共通: ファイルリスト更新を伴うもの) ---
        function performAction(action, data) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('current_dir', currentDirectoryRelativePath);
            
            for (const key in data) {
                // 配列の場合は個別にappendする
                if (Array.isArray(data[key])) {
                    data[key].forEach(item => {
                        formData.append(`${key}[]`, item);
                    });
                } else {
                    formData.append(key, data[key]);
                }
            }

            showSpinner();
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                hideSpinner();
                showMessage(result.message, result.status);
                if (result.status === 'success' || result.status === 'warning') {
                    // 成功または警告の場合はファイルリストを再取得・更新
                    fetchFileList(currentDirectoryRelativePath);
                }
            })
            .catch(error => {
                hideSpinner();
                console.error('Error:', error);
                showMessage('サーバーとの通信中にエラーが発生しました。', 'error');
            });
        }

        // --- ファイルリストをAjaxで取得し更新する関数 ---
        function fetchFileList(path = '') {
            const formData = new FormData();
            formData.append('action', 'get_file_list_ajax');
            formData.append('current_dir', path);

            showSpinner();
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideSpinner();
                if (data.status === 'success') {
                    currentDirectoryRelativePath = data.current_path_relative;
                    currentValidPathParts = data.valid_path_parts_for_display;

                    renderFileList(data.items);
                    updateBreadcrumbs();
                    const newUrl = currentDirectoryRelativePath ? `?path=${encodeURIComponent(currentDirectoryRelativePath)}` : 'index.php';
                    history.pushState({ path: currentDirectoryRelativePath }, '', newUrl);
                    updateToolbarButtons();
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                hideSpinner();
                console.error('Error fetching file list:', error);
                showMessage('ファイルリストの取得に失敗しました。', 'error');
            });
        }

        window.addEventListener('popstate', (event) => {
            const path = event.state && event.state.path ? event.state.path : '';
            currentDirectoryRelativePath = path;
            fetchFileList(path);
        });


        // --- ディレクトリツリーの取得とレンダリング (移動/コピーモーダル用) ---
        function fetchDirectoryTree(basePath = '') {
            const formData = new FormData();
            formData.append('action', 'get_directory_tree_ajax');
            formData.append('path', basePath);
            formData.append('current_dir', currentDirectoryRelativePath);

            showSpinner();
            fetch('index.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                hideSpinner();
                if (data.status === 'success') {
                    directoryTreeView.innerHTML = renderDirectoryTree(data.directories);
                    attachTreeEventListeners();
                } else {
                    showMessage(data.message, 'error');
                    directoryTreeView.innerHTML = '<p>ディレクトリツリーの取得に失敗しました。</p>';
                }
            })
            .catch(error => {
                hideSpinner();
                console.error('Error fetching directory tree:', error);
                showMessage('ディレクトリツリーの取得に失敗しました。', 'error');
                directoryTreeView.innerHTML = '<p>ディレクトリツリーの取得に失敗しました。</p>';
            });
        }

        function renderDirectoryTree(directories) {
            let html = '<ul>';
            html += `<li>
                        <span class="folder-toggle">
                            <i class="fas fa-folder folder-icon"></i> <a href="#" data-path="" class="dir-node">ルート</a>
                        </span>
                        <ul class="nested active">`;

            directories.forEach(dir => {
                html += `<li>
                            <span class="folder-toggle">
                                <i class="fas fa-folder folder-icon"></i> <a href="#" data-path="${escapeHtml(dir.relative_path)}" class="dir-node">${escapeHtml(dir.name)}</a>
                            </span>`;
                if (dir.children && dir.children.length > 0) {
                    html += `<ul class="nested">${renderDirectoryTree(dir.children)}</ul>`;
                }
                html += `</li>`;
            });
            html += `</ul></li></ul>`;
            return html;
        }

        function attachTreeEventListeners() {
            directoryTreeView.querySelectorAll('.dir-node').forEach(node => {
                node.addEventListener('click', (e) => {
                    e.preventDefault();
                    directoryTreeView.querySelectorAll('.dir-node.selected').forEach(s => s.classList.remove('selected'));
                    e.target.classList.add('selected');
                    selectedMoveCopyDirectory = e.target.dataset.path;
                    selectedMoveCopyPath.textContent = selectedMoveCopyDirectory === '' ? 'ルート' : selectedMoveCopyDirectory;
                });
            });

            directoryTreeView.querySelectorAll('.folder-toggle').forEach(toggle => {
                toggle.addEventListener('click', (e) => {
                    if (e.target.tagName === 'A' || e.target.closest('a')) return;
                    const nestedUl = toggle.nextElementSibling;
                    if (nestedUl && nestedUl.classList.contains('nested')) {
                        nestedUl.classList.toggle('active');
                        const icon = toggle.querySelector('.fas');
                        if (icon) {
                            icon.classList.toggle('fa-folder-open');
                            icon.classList.toggle('fa-folder');
                        }
                    }
                });
            });
        }


        // --- ヘルパー関数 ---
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

    </script>
</body>
</html>
