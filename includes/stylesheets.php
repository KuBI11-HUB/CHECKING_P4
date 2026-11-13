<?php
$cssFiles = [
    'style.css',
    'login.css',
    'upload.css',
    'admin-results.css',
    'navigation.css',
    'portal.css',
    'dashboard.css',
    'responsive.css',
    'theme.css',
];
foreach ($cssFiles as $cssFile):
    $cssPath = __DIR__ . '/../assets/css/' . $cssFile;
?>
    <link rel="stylesheet" href="/GRANTED/assets/css/<?php echo $cssFile; ?>?v=<?php echo (int) filemtime($cssPath); ?>">
<?php endforeach; ?>
