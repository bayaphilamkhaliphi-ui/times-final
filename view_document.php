<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit;
}

require_once 'db.php';

$document_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id = $_SESSION['user_id'];

if ($document_id <= 0) {
    die("Invalid document ID.");
}

// Make sure the document actually exists
$docCheck = $conn->prepare("SELECT id FROM documents WHERE id = ?");
$docCheck->bind_param("i", $document_id);
$docCheck->execute();
$docExists = $docCheck->get_result()->fetch_assoc();
$docCheck->close();

if (!$docExists) {
    die("Document not found.");
}

// Every logged-in user can view every document, so find their tracking
// row for this document, or create one on first visit.
$find = $conn->prepare("SELECT id FROM employee_documents WHERE employee_id = ? AND document_id = ?");
$find->bind_param("ii", $user_id, $document_id);
$find->execute();
$existing = $find->get_result()->fetch_assoc();
$find->close();

if ($existing) {
    $assignment_id = $existing['id'];
} else {
    $create = $conn->prepare("INSERT INTO employee_documents (employee_id, document_id, status) VALUES (?, ?, 'pending')");
    $create->bind_param("ii", $user_id, $document_id);
    $create->execute();
    $assignment_id = $create->insert_id;
    $create->close();
}

// Fetch assignment + document details
$stmt = $conn->prepare("
    SELECT ed.*, d.title, d.category, d.description, d.filename
    FROM employee_documents ed
    JOIN documents d ON ed.document_id = d.id
    WHERE ed.id = ? AND ed.employee_id = ?
");
$stmt->bind_param("ii", $assignment_id, $user_id);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assignment) {
    die("Document not found.");
}

$filename = $assignment['filename'];
$file_path = "uploads/" . $filename;
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

// If already agreed, show a message and exit (no need to log view)
if ($assignment['status'] === 'agreed') {
    $agreed_at = $assignment['agreed_at'];
    // We'll show the agreed card later; we still render the page but with no form.
}

// Log "viewed" action if status is pending (or viewed already? we only log once)
// We'll log only if status is 'pending' (first view) to avoid duplicate logs.
if ($assignment['status'] === 'pending') {
    // Update viewed_at and status to 'viewed'
    $update = $conn->prepare("UPDATE employee_documents SET viewed_at = NOW(), status = 'viewed' WHERE id = ?");
    $update->bind_param("i", $assignment_id);
    $update->execute();
    $update->close();

    // Insert into agreement_logs
    $log = $conn->prepare("INSERT INTO agreement_logs (employee_document_id, action, ip_address, user_agent) VALUES (?, 'viewed', ?, ?)");
    $ip = $_SERVER['REMOTE_ADDR'];
    $ua = $_SERVER['HTTP_USER_AGENT'];
    $log->bind_param("iss", $assignment_id, $ip, $ua);
    $log->execute();
    $log->close();

    // Refresh assignment data to reflect new status
    $assignment['status'] = 'viewed';
}

// Handle agreement submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agree_btn'])) {
    // Double-check status again
    if ($assignment['status'] !== 'agreed') {
        // Update status to 'agreed'
        $update = $conn->prepare("UPDATE employee_documents SET status = 'agreed', agreed_at = NOW() WHERE id = ?");
        $update->bind_param("i", $assignment_id);
        $update->execute();
        $update->close();

        // Log agreement
        $log = $conn->prepare("INSERT INTO agreement_logs (employee_document_id, action, ip_address, user_agent) VALUES (?, 'agreed', ?, ?)");
        $ip = $_SERVER['REMOTE_ADDR'];
        $ua = $_SERVER['HTTP_USER_AGENT'];
        $log->bind_param("iss", $assignment_id, $ip, $ua);
        $log->execute();
        $log->close();

        // Redirect to show success message
        header("Location: view_document.php?id=" . $document_id . "&success=1");
        exit;
    }
}

$show_success_toast = isset($_GET['success']) && $_GET['success'] == 1;
$has_agreed = ($assignment['status'] === 'agreed');
$agreement_date = $has_agreed ? $assignment['agreed_at'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Read & Agree: <?php echo htmlspecialchars($assignment['title']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #8B0000;
            --primary-hover: #600000;
            --text-dark: #333333;
            --text-light: #666666;
            --border-color: #e0e0e0;
            --bg-light: #f4f6f9;
            --success-color: #2e7d32;
        }
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins',sans-serif; }
        body { background: var(--bg-light); color: var(--text-dark); display: flex; flex-direction: column; min-height: 100vh; }
        
        header { background: var(--primary-color); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        header a { color: white; text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 8px; font-weight: 500; transition: opacity 0.3s; }
        header a:hover { opacity: 0.8; }
        header h2 { font-size: 18px; font-weight: 600; }
        
        .main-container { width: 90%; max-width: 1200px; margin: 30px auto; display: grid; grid-template-columns: 1fr; gap: 30px; flex-grow: 1; }
        
        .card { background: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); padding: 25px; }
        
        .doc-meta-card { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; }
        .doc-category { display: inline-block; background: rgba(139,0,0,0.1); color: var(--primary-color); padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; align-self: flex-start; text-transform: uppercase; }
        .doc-title { font-size: 24px; font-weight: 700; color: var(--primary-color); }
        .doc-desc { font-size: 14px; color: var(--text-light); line-height: 1.6; border-left: 3px solid var(--border-color); padding-left: 15px; margin-top: 5px; }
        
        .viewer-container { width: 100%; height: 75vh; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; background: #eaeaea; position: relative; }
        .pdf-frame { width: 100%; height: 100%; border: none; }
        
        .unsupported-view { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; text-align: center; padding: 40px; background: white; }
        .unsupported-icon { font-size: 60px; margin-bottom: 15px; }
        .unsupported-view h3 { font-size: 20px; margin-bottom: 10px; color: var(--text-dark); }
        .unsupported-view p { color: var(--text-light); font-size: 14px; margin-bottom: 25px; max-width: 500px; line-height: 1.5; }
        .download-btn { background: var(--primary-color); color: white; border: none; padding: 12px 25px; border-radius: 6px; font-size: 15px; font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 6px rgba(139,0,0,0.15); display: inline-flex; align-items: center; gap: 10px; }
        .download-btn:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 12px rgba(139,0,0,0.25); }
        
        #scroll-sentinel { height: 10px; width: 100%; margin-top: 15px; }
        
        .agreement-section {
            opacity: 0.3;
            filter: blur(2px);
            pointer-events: none;
            transition: all 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            transform: translateY(20px);
            background: #fdfdfd;
            border: 2px dashed #ccc;
            padding: 30px;
            border-radius: 12px;
            margin-top: 30px;
            text-align: center;
        }
        
        .agreement-section.unlocked {
            opacity: 1;
            filter: blur(0);
            pointer-events: auto;
            transform: translateY(0);
            background: #ffffff;
            border: 2px solid var(--primary-color);
            box-shadow: 0 15px 35px rgba(139,0,0,0.1);
        }
        
        .agreement-lock-msg { color: var(--text-light); font-size: 14px; font-weight: 500; margin-bottom: 10px; transition: opacity 0.3s; }
        .agreement-section.unlocked .agreement-lock-msg { display: none; }
        
        .agreement-prompt { font-size: 16px; font-weight: 600; color: var(--text-dark); margin-bottom: 20px; }
        
        .checkbox-container {
            display: inline-flex;
            align-items: center;
            position: relative;
            padding-left: 35px;
            margin-bottom: 20px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            user-select: none;
            color: var(--text-dark);
            text-align: left;
            max-width: 600px;
        }
        .checkbox-container input { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
        .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            height: 24px;
            width: 24px;
            background-color: #eee;
            border-radius: 6px;
            border: 2px solid var(--border-color);
            transition: all 0.2s ease;
        }
        .checkbox-container:hover input ~ .checkmark { background-color: #ddd; }
        .checkbox-container input:checked ~ .checkmark { background-color: var(--primary-color); border-color: var(--primary-color); }
        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }
        .checkbox-container input:checked ~ .checkmark:after { display: block; }
        .checkbox-container .checkmark:after {
            left: 8px;
            top: 4px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        
        .submit-agree-btn { background: var(--primary-color); color: white; border: none; padding: 12px 35px; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 6px rgba(139,0,0,0.15); width: 100%; max-width: 250px; }
        .submit-agree-btn:hover:not(:disabled) { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 12px rgba(139,0,0,0.25); }
        .submit-agree-btn:disabled { background: #cccccc; cursor: not-allowed; box-shadow: none; }
        
        .agreed-card { background: #e8f5e9; border: 2px solid var(--success-color); border-radius: 12px; padding: 30px; text-align: center; margin-top: 30px; box-shadow: 0 10px 25px rgba(46,125,50,0.05); }
        .agreed-icon { font-size: 40px; color: var(--success-color); margin-bottom: 10px; }
        .agreed-title { font-size: 18px; font-weight: 700; color: var(--success-color); margin-bottom: 5px; }
        .agreed-sub { font-size: 14px; color: #555; }
        
        #toast { visibility: hidden; min-width: 280px; background-color: #2e7d32; color: #fff; text-align: center; border-radius: 8px; padding: 15px; position: fixed; z-index: 1000; right: 30px; bottom: 30px; font-size: 15px; font-weight: 500; box-shadow: 0 5px 15px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; gap: 10px; }
        #toast.show { visibility: visible; animation: fadein 0.5s, fadeout 0.5s 2.5s; }
        @keyframes fadein { from {bottom: 0; opacity: 0;} to {bottom: 30px; opacity: 1;} }
        @keyframes fadeout { from {bottom: 30px; opacity: 1;} to {bottom: 0; opacity: 0;} }
    </style>
</head>
<body>
    <header>
        <a href="employee_documents.php?category=<?php echo urlencode($assignment['category']); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
            </svg>
            Back to List
        </a>
        <h2>Document Portal</h2>
        <div style="width: 80px;"></div>
    </header>

    <div class="main-container">
        <div class="card">
            <div class="doc-meta-card">
                <span class="doc-category"><?php echo htmlspecialchars($assignment['category']); ?></span>
                <h1 class="doc-title"><?php echo htmlspecialchars($assignment['title']); ?></h1>
                <?php if (!empty($assignment['description'])): ?>
                    <p class="doc-desc"><?php echo nl2br(htmlspecialchars($assignment['description'])); ?></p>
                <?php endif; ?>
            </div>

            <div class="viewer-container">
                <?php if ($ext === 'pdf'): ?>
                    <iframe src="<?php echo htmlspecialchars($file_path); ?>#toolbar=0" class="pdf-frame"></iframe>
                <?php else: ?>
                    <div class="unsupported-view">
                        <div class="unsupported-icon">
                            <?php 
                            if (in_array($ext, ['xlsx', 'xls'])) echo "📊";
                            else if (in_array($ext, ['docx', 'doc'])) echo "📝";
                            else echo "📄";
                            ?>
                        </div>
                        <h3><?php echo htmlspecialchars($filename); ?></h3>
                        <p>This is a <?php echo strtoupper($ext); ?> document. Browser security configurations prevent live previews. Please download the document to read it on your system.</p>
                        <a href="<?php echo htmlspecialchars($file_path); ?>" class="download-btn" id="download-trigger" download>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" style="margin-top:-2px">
                                <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                                <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                            </svg>
                            Download & Read Document
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div id="scroll-sentinel"></div>

            <?php if ($has_agreed): ?>
                <div class="agreed-card">
                    <div class="agreed-icon">✓</div>
                    <div class="agreed-title">You have agreed to this document</div>
                    <div class="agreed-sub">Agreement registered on <?php echo date('F d, Y \a\t h:i A', strtotime($agreement_date)); ?></div>
                </div>
            <?php else: ?>
                <div class="agreement-section" id="agreement-card">
                    <div class="agreement-lock-msg" id="lock-msg">
                        🔒 Please scroll down to the bottom of the document to unlock the agreement section.
                    </div>
                    <form method="POST" action="">
                        <div class="agreement-prompt">Acknowledge Agreement</div>
                        <label class="checkbox-container">
                            I agree to the terms and guidelines outlined in this <?php echo htmlspecialchars($assignment['category']); ?>.
                            <input type="checkbox" id="agree-check" name="agree_check">
                            <span class="checkmark"></span>
                        </label>
                        <br>
                        <button type="submit" class="submit-agree-btn" id="agree-submit-btn" name="agree_btn" disabled>
                            Submit Agreement
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="toast">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
            <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
        </svg>
        Agreement submitted successfully!
    </div>

    <script>
        <?php if ($show_success_toast): ?>
            const toast = document.getElementById('toast');
            toast.className = "show";
            setTimeout(function() { toast.className = toast.className.replace("show", ""); }, 3000);
        <?php endif; ?>

        const agreementCard = document.getElementById('agreement-card');
        const agreeCheck = document.getElementById('agree-check');
        const agreeBtn = document.getElementById('agree-submit-btn');
        const isPdf = <?php echo ($ext === 'pdf') ? 'true' : 'false'; ?>;
        
        if (agreementCard) {
            agreeCheck.addEventListener('change', function() {
                agreeBtn.disabled = !this.checked;
            });

            const sentinel = document.getElementById('scroll-sentinel');
            const downloadTrigger = document.getElementById('download-trigger');
            if (downloadTrigger) {
                downloadTrigger.addEventListener('click', function() {
                    unlockAgreement();
                });
            }

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        unlockAgreement();
                        observer.unobserve(entry.target);
                    }
                });
            }, {
                root: null,
                rootMargin: '0px 0px 50px 0px',
                threshold: 0.1
            });

            observer.observe(sentinel);

            function unlockAgreement() {
                agreementCard.classList.add('unlocked');
            }
        }
    </script>
</body>
</html>
<?php
$conn->close();
?>