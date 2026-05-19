<?php
/**
 * Helper functions for user profile management
 */

/**
 * Get user's profile image path
 * @param int $userId User ID
 * @param PDO $pdo Database connection
 * @return string Profile image path
 */
function getUserProfileImage($userId, $pdo) {
    $defaultImage = "argon-dashboard-master/assets/img/ship.jpg";
    
    try {
        $stmt = $pdo->prepare("SELECT profile_image FROM admin_users WHERE id = ?");
        $stmt->execute([$userId]);
        $profileImage = $stmt->fetchColumn();
        
        if (!empty($profileImage) && file_exists(__DIR__ . "/../uploads/profile_images/" . $profileImage)) {
            return "uploads/profile_images/" . $profileImage;
        }
    } catch (Exception $e) {
        // If there's an error or no profile_image column, return default
        return $defaultImage;
    }
    
    return $defaultImage;
}

/**
 * Handle profile image upload
 * @param array $file $_FILES array element
 * @param int $userId User ID
 * @param PDO $pdo Database connection
 * @return array Result with success status and message
 */
function handleProfileImageUpload($file, $userId, $pdo) {
    $uploadDir = __DIR__ . "/../uploads/profile_images/";
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    
    // Create upload directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error occurred.'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File size too large. Maximum 2MB allowed.'];
    }
    
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and GIF allowed.'];
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'profile_' . $userId . '_' . time() . '.' . $extension;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Resize for faster loading if GD is available
        if (function_exists('imagecreatetruecolor')) {
            resizeProfileImage($filepath, 240, 240);
        }
        // Update database
        try {
            // First, try to add the profile_image column if it doesn't exist
            try {
                $pdo->exec("ALTER TABLE admin_users ADD COLUMN profile_image VARCHAR(255) DEFAULT NULL");
            } catch (Exception $e) {
                // Column probably already exists, continue
            }
            
            // Update user's profile image
            $stmt = $pdo->prepare("UPDATE admin_users SET profile_image = ? WHERE id = ?");
            $stmt->execute([$filename, $userId]);
            
            // Remove old profile image if it exists
            $stmt = $pdo->prepare("SELECT profile_image FROM admin_users WHERE id = ?");
            $stmt->execute([$userId]);
            $oldImage = $stmt->fetchColumn();
            
            if ($oldImage && $oldImage !== $filename && file_exists($uploadDir . $oldImage)) {
                unlink($uploadDir . $oldImage);
            }
            
            return ['success' => true, 'message' => 'Profile image updated successfully!'];
        } catch (Exception $e) {
            // Remove uploaded file if database update fails
            unlink($filepath);
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    } else {
        return ['success' => false, 'message' => 'Failed to upload file.'];
    }
}

/**
 * Resize and crop profile image to a square thumbnail.
 *
 * @param string $filepath
 * @param int $targetWidth
 * @param int $targetHeight
 * @return void
 */
function resizeProfileImage($filepath, $targetWidth, $targetHeight) {
    $info = getimagesize($filepath);
    if (!$info) {
        return;
    }

    $mime = $info['mime'] ?? '';
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $source = imagecreatefromjpeg($filepath);
            break;
        case 'image/png':
            $source = imagecreatefrompng($filepath);
            break;
        case 'image/gif':
            $source = imagecreatefromgif($filepath);
            break;
        default:
            return;
    }

    if (!$source) {
        return;
    }

    $srcWidth = imagesx($source);
    $srcHeight = imagesy($source);
    $size = min($srcWidth, $srcHeight);
    $srcX = (int)(($srcWidth - $size) / 2);
    $srcY = (int)(($srcHeight - $size) / 2);

    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($mime === 'image/png' || $mime === 'image/gif') {
        imagecolortransparent($target, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagealphablending($target, false);
        imagesavealpha($target, true);
    }

    imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, $size, $size);

    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            imagejpeg($target, $filepath, 85);
            break;
        case 'image/png':
            imagepng($target, $filepath, 6);
            break;
        case 'image/gif':
            imagegif($target, $filepath);
            break;
    }

    imagedestroy($source);
    imagedestroy($target);
}
?>
