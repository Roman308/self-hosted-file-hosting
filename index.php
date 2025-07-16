<?php
$dir = 'uploads/';
$dbFile = 'downloads.sqlite';

// Create the uploads directory if it doesn't exist
if (!file_exists($dir)) {
    mkdir($dir, 0755, true);
}

// Initialize SQLite database if it doesn't exist
$db = new PDO('sqlite:' . $dbFile);
$db->exec("CREATE TABLE IF NOT EXISTS downloads (file TEXT PRIMARY KEY, count INTEGER DEFAULT 0)");

// Fetch download counts into an array
$stmt = $db->query("SELECT * FROM downloads");
$logArray = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $logArray[$row['file']] = $row['count'];
}

// Handle file download
if (isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $file = htmlspecialchars($file, ENT_QUOTES, 'UTF-8'); // Prevent XSS
    $filePath = $dir . $file;

    if (file_exists($filePath)) {
        // Update download count
        if (isset($logArray[$file])) {
            $logArray[$file]++;
            $db->prepare("UPDATE downloads SET count = ? WHERE file = ?")->execute([$logArray[$file], $file]);
        } else {
            $logArray[$file] = 1;
            $db->prepare("INSERT INTO downloads (file, count) VALUES (?, ?)")->execute([$file, 1]);
        }

        // Initiate file download in chunks
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        // Flush the output buffer and turn off output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        $chunkSize = 1024 * 1024; // 1MB chunks
        $handle = fopen($filePath, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, $chunkSize);
                flush();
            }
            fclose($handle);
        }
        exit;
    } else {
        error_log("File not found: $filePath", 0);
        echo "File not found.";
    }
}

// Fetch files and filter based on search
$files = [];
if (is_dir($dir)) {
    if ($dh = opendir($dir)) {
        while (($file = readdir($dh)) !== false) {
            if ($file != "." && $file != "..") {
                if (isset($_GET['search']) && !empty($_GET['search'])) {
                    if (stripos($file, $_GET['search']) === false) {
                        continue;
                    }
                }
                $files[] = $file;
            }
        }
        closedir($dh);
    }
}

// Pagination
$perPage = 10;
$totalFiles = count($files);
$totalPages = ceil($totalFiles / $perPage);
$currentPage = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, min($totalPages, $currentPage));
$start = ($currentPage - 1) * $perPage;

// Display files with pagination
$filesToDisplay = array_slice($files, $start, $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Files - RomanNoodles</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="styles.css">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script>
        $(document).ready(function() {
            // Load file list asynchronously
            function loadFiles() {
                $.ajax({
                    url: 'load_files.php',
                    type: 'GET',
                    success: function(response) {
                        $('#fileTable tbody').html(response);
                    }
                });
            }

            loadFiles();

            // Sort files
            $('.sort').click(function() {
                var sortType = $(this).data('sort');
                $.ajax({
                    url: 'load_files.php',
                    type: 'GET',
                    data: { sort: sortType },
                    success: function(response) {
                        $('#fileTable tbody').html(response);
                    }
                });
            });

            // Enhanced search functionality
            $('#searchForm').submit(function(event) {
                event.preventDefault();
                var searchQuery = $('#searchInput').val();
                $.ajax({
                    url: 'load_files.php',
                    type: 'GET',
                    data: { search: searchQuery },
                    success: function(response) {
                        $('#fileTable tbody').html(response);
                    }
                });
            });
        });
    </script>
</head>
<body>
    <div class="container mt-5">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h1 class="my-4 text-center">RomanNoodles - Available Files</h1>
            </div>
            <div class="card-body">
                <form id="searchForm" method="GET" class="mb-3">
                    <input type="text" id="searchInput" name="search" placeholder="Search files..." class="form-control" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    <button type="submit" class="btn btn-primary mt-2">Search</button>
                </form>
                <div class="table-responsive">
                    <table id="fileTable" class="table table-hover">
                        <thead class="thead-dark">
                            <tr>
                                <th><button class="sort btn btn-link" data-sort="name">File Name</button></th>
                                <th><button class="sort btn btn-link" data-sort="type">Type</button></th>
                                <th><button class="sort btn btn-link" data-sort="size">Size</button></th>
                                <th><button class="sort btn btn-link" data-sort="date">Date Uploaded</button></th>
                                <th><button class="sort btn btn-link" data-sort="downloads">Downloads</button></th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filesToDisplay as $file): ?>
                                <?php
                                $filePath = $dir . $file;
                                $fileSize = filesize($filePath);
                                $fileDate = date("F d Y, H:i:s", filemtime($filePath));
                                $downloads = isset($logArray[$file]) ? $logArray[$file] : 0;
                                $fileType = pathinfo($filePath, PATHINFO_EXTENSION);
                                $icon = ($fileType == 'pdf') ? '📄' :
                                        (($fileType == 'jpg' || $fileType == 'png') ? '🖼️' :
                                        (($fileType == 'txt') ? '🗒️' : '📦'));
                                ?>
                                <tr>
                                    <td><?php echo $icon . " " . $file; ?></td>
                                    <td><?php echo $fileType; ?></td>
                                    <td><?php echo round($fileSize / 1024, 2) . " KB"; ?></td>
                                    <td><?php echo $fileDate; ?></td>
                                    <td><?php echo $downloads; ?></td>
                                    <td><a href="index.php?file=<?php echo $file; ?>" class="btn btn-success">Download</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                                <li class="page-item <?php echo ($page == $currentPage) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page; ?>"><?php echo $page; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            </div>
            <div class="card-footer text-muted text-center">
                © 2024 RomanNoodles. All rights reserved.
            </div>
        </div>
    </div>
</body>
</html>
