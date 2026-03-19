<?php
/**
 * 1Diff Forum API - 纯 API 发帖接口
 * 
 * 使用方法:
 * POST /api_post.php
 * Headers:
 *   - Authorization: Token YOUR_API_TOKEN
 *   - Content-Type: application/json
 * 
 * Body:
 * {
 *   "title": "帖子标题",
 *   "content": "帖子内容",
 *   "tag_id": 2
 * }
 */

require __DIR__ . '/../vendor/autoload.php';

// 配置
$config = require __DIR__ . '/../config.php';

// 获取请求头
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

// 验证 API Token
if (!preg_match('/Token\s+(.+)/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Missing or invalid Authorization header']);
    exit;
}

$token = $matches[1];

// 连接数据库验证 token
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $config['database']['host'],
    $config['database']['database'],
    $config['database']['charset']
);

try {
    $pdo = new PDO($dsn, $config['database']['username'], $config['database']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 验证 token
    $stmt = $pdo->prepare("SELECT user_id FROM access_tokens WHERE token = ? AND type = 'api' LIMIT 1");
    $stmt->execute([$token]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'message' => 'Invalid API token']);
        exit;
    }
    
    $userId = $result['user_id'];
    
    // 获取请求体
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['title']) || !isset($input['content'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad Request', 'message' => 'Missing title or content']);
        exit;
    }
    
    $title = $input['title'];
    $content = $input['content'];
    $tagId = isset($input['tag_id']) ? intval($input['tag_id']) : 1;
    
    // 生成 slug
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
    $slug = substr($slug, 0, 100);
    
    // 创建讨论
    $stmt = $pdo->prepare("INSERT INTO discussions (title, slug, user_id, created_at, comment_count, participant_count, is_private, is_approved, is_locked, is_sticky, last_posted_at, last_posted_user_id, last_post_number) VALUES (?, ?, ?, NOW(), 1, 1, 0, 1, 0, 0, NOW(), ?, 1)");
    $stmt->execute([$title, $slug, $userId, $userId]);
    $discussionId = $pdo->lastInsertId();
    
    // 创建帖子内容
    $stmt = $pdo->prepare("INSERT INTO posts (discussion_id, user_id, type, content, created_at, number, is_private, is_approved) VALUES (?, ?, 'comment', ?, NOW(), 1, 0, 1)");
    $stmt->execute([$discussionId, $userId, $content]);
    $postId = $pdo->lastInsertId();
    
    // 更新讨论
    $stmt = $pdo->prepare("UPDATE discussions SET first_post_id = ?, last_post_id = ? WHERE id = ?");
    $stmt->execute([$postId, $postId, $discussionId]);
    
    // 关联标签
    $stmt = $pdo->prepare("INSERT INTO discussion_tag (discussion_id, tag_id) VALUES (?, ?)");
    $stmt->execute([$discussionId, $tagId]);
    
    // 清除缓存
    // 注意：这里需要手动清除 Flarum 缓存
    
    // 返回成功响应
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'data' => [
            'type' => 'discussions',
            'id' => $discussionId,
            'attributes' => [
                'title' => $title,
                'slug' => $slug,
                'createdAt' => date('Y-m-d H:i:s')
            ],
            'links' => [
                'self' => '/d/' . $discussionId . '-' . $slug
            ]
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database Error', 'message' => $e->getMessage()]);
}
