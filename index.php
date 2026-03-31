<?php
require_once __DIR__ . '/helpers/functions.php';

if (!isInstalled()) { header('Location: ' . BASE_URL . '/install.php'); exit; }
if (isset($_SESSION['user_id'])) { header('Location: ' . BASE_URL . '/dashboard.php'); exit; }

$appName = getAppName();
$appLogoUrl = getAppLogoUrl();

$programIcons = [
    'BSAB' => 'fa-seedling',   'BSIT' => 'fa-laptop-code',
    'BSCM' => 'fa-handshake',  'BSHM' => 'fa-concierge-bell',
    'BSTM' => 'fa-plane-departure', 'BSM' => 'fa-heartbeat',
];
$programColors = [
    'BSAB' => ['#D1FAE5','#059669'], 'BSIT' => ['#EEF2FF','#4F46E5'],
    'BSCM' => ['#FEF3C7','#D97706'], 'BSHM' => ['#FCE7F3','#DB2777'],
    'BSTM' => ['#CFFAFE','#0891B2'], 'BSM'  => ['#FEE2E2','#DC2626'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Ilocos Sur Community College - Learning Management System">
    <title><?= e($appName) ?> - Learning Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?= e($appLogoUrl) ?>">
</head>
<body class="lp-body">

<nav class="lp-topbar">
    <div class="container">
        <div class="lp-topbar-inner">
            <a href="<?= BASE_URL ?>/" class="lp-brand">
                <img src="<?= e($appLogoUrl) ?>" alt="<?= e($appName) ?>" class="lp-brand-logo">
                <div class="lp-brand-text">
                    <strong><?= e($appName) ?></strong>
                    <span>Learning Management System</span>
                </div>
            </a>
            <div class="lp-nav-links">
                <a href="#about">About</a>
                <a href="#programs">Programs</a>
                <a href="#contact">Contact</a>
            </div>
            <a href="<?= BASE_URL ?>/login.php" class="lp-login-btn">
                <i class="fas fa-sign-in-alt"></i> <span>Sign In</span>
            </a>
        </div>
    </div>
</nav>

<section class="lp-hero">
    <div class="container">
        <div class="lp-hero-grid">
            <div class="lp-hero-left">
                <span class="lp-est-tag">Est. 1975 &middot; Bantay, Ilocos Sur</span>
                <h1><?= e($appName) ?></h1>
                <p class="lp-hero-lead">A public higher education institution providing free, quality education to the youth of the Ilocos Region — building productive citizens through academic and technical-vocational programs since 1975.</p>
                <div class="lp-hero-btns">
                    <a href="<?= BASE_URL ?>/login.php" class="lp-btn-solid"><i class="fas fa-arrow-right"></i> Go to LMS Portal</a>
                    <a href="#about" class="lp-btn-outline">Learn More</a>
                </div>
                <div class="lp-hero-stats">
                    <div class="lp-stat-pill"><strong>6</strong> Degree Programs</div>
                    <div class="lp-stat-pill"><strong>Free</strong> Tuition</div>
                    <div class="lp-stat-pill"><strong>CHED</strong> Regulated</div>
                </div>
            </div>
            <div class="lp-hero-right">
                <div class="lp-hero-logo-block">
                    <img src="<?= e($appLogoUrl) ?>" alt="<?= e($appName) ?> seal">
                </div>
            </div>
        </div>
    </div>
</section>

<section class="lp-about" id="about">
    <div class="container">
        <div class="lp-ribbon">
            <div class="lp-ribbon-item lp-ribbon-vision">
                <div class="lp-ribbon-icon"><i class="fas fa-eye"></i></div>
                <div>
                    <h4>Vision</h4>
                    <p>To be a center of excellence for academic, ladderized programs, Technical and Vocational Educational Training (TVET) Programs, and a self-sustainable educational institution with highly competitive teaching and non-teaching staff.</p>
                </div>
            </div>
            <div class="lp-ribbon-item lp-ribbon-mission">
                <div class="lp-ribbon-icon"><i class="fas fa-bullseye"></i></div>
                <div>
                    <h4>Mission</h4>
                    <p>To educate and train the youth of the Ilocos Region whose parents are tobacco farmers to enhance labor productivity and responsible citizenship in an environment where educational access is equitable.</p>
                </div>
            </div>
        </div>

        <div class="lp-history-strip">
            <div class="lp-history-marker">
                <div class="lp-marker-year">1975</div>
                <div class="lp-marker-line"></div>
                <div class="lp-marker-dot"></div>
            </div>
            <div class="lp-history-body">
                <p>The Ilocos Sur Public College (ISPC) was founded as a priority project of Honorable Luis "Chavit" C. Singson, then Governor of Ilocos Sur, with the passage of Sangguniang Panlalawigan Resolution No. 523 — governed by a Board of Trustees and supported by the Municipality of Vigan and the Rotary Club of Vigan. Today it continues as <strong>Ilocos Sur Community College</strong>, offering free tuition for degree programs regulated by the Commission on Higher Education (CHED).</p>
            </div>
        </div>
    </div>
</section>

<section class="lp-programs" id="programs">
    <div class="container">
        <h2 class="lp-section-heading">Academic Programs Offered</h2>
        <div class="lp-programs-grid">
            <?php foreach (PROGRAMS as $code => $name):
                $bg = $programColors[$code][0] ?? '#F1F5F9';
                $fg = $programColors[$code][1] ?? '#475569';
                $icon = $programIcons[$code] ?? 'fa-book';
            ?>
            <div class="lp-program-tile" style="background:<?= $bg ?>;">
                <div class="lp-program-tile-icon" style="color:<?= $fg ?>;"><i class="fas <?= $icon ?>"></i></div>
                <span class="lp-program-code" style="color:<?= $fg ?>;"><?= $code ?></span>
                <span class="lp-program-full"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="lp-features" id="features">
    <div class="container">
        <h2 class="lp-section-heading">What You Can Do on the LMS</h2>
        <div class="lp-feature-list">
            <div class="lp-feature-row">
                <span class="lp-ft-dot" style="background:var(--primary);"></span>
                <div>
                    <strong>Knowledge Tree</strong>
                    <em>Progress through structured, level-based learning paths — unlock topics as you advance.</em>
                </div>
            </div>
            <div class="lp-feature-row">
                <span class="lp-ft-dot" style="background:var(--success);"></span>
                <div>
                    <strong>Quizzes &amp; Word Scramble</strong>
                    <em>Take interactive assessments that make reviewing concepts more engaging.</em>
                </div>
            </div>
            <div class="lp-feature-row">
                <span class="lp-ft-dot" style="background:var(--accent);"></span>
                <div>
                    <strong>Growth Tracker</strong>
                    <em>See your academic progress month over month with visual performance data.</em>
                </div>
            </div>
            <div class="lp-feature-row">
                <span class="lp-ft-dot" style="background:var(--danger);"></span>
                <div>
                    <strong>Badges &amp; Achievements</strong>
                    <em>Earn recognition for completing milestones — visible on your dashboard.</em>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="lp-contact" id="contact">
    <div class="container">
        <h2 class="lp-section-heading">Contact &amp; Location</h2>
        <div class="lp-contact-bar">
            <div class="lp-contact-item">
                <i class="fas fa-map-marker-alt"></i>
                <div>
                    <strong>Campus</strong>
                    Quirino Stadium, Zone V, Bantay, Ilocos Sur, Philippines
                </div>
            </div>
            <div class="lp-contact-item">
                <i class="fas fa-phone-alt"></i>
                <div>
                    <strong>Phone</strong>
                    (077) 604-0285 / (077) 722-8007
                </div>
            </div>
            <div class="lp-contact-item">
                <i class="fas fa-envelope"></i>
                <div>
                    <strong>Email</strong>
                    isccbantay@yahoo.com
                </div>
            </div>
            <div class="lp-contact-item">
                <i class="fab fa-facebook"></i>
                <div>
                    <strong>Facebook</strong>
                    <a href="https://www.facebook.com/ISCC.OfficialPage/" target="_blank">ISCC Official Page</a>
                </div>
            </div>
        </div>
    </div>
</section>

<footer class="lp-footer">
    <div class="container">
        <div class="lp-footer-inner">
            <div class="lp-footer-left">
                <img src="<?= e($appLogoUrl) ?>" alt="<?= e($appName) ?>" class="lp-footer-logo">
                <div>
                    <strong><?= e($appName) ?></strong>
                    <span>&copy; <?= date('Y') ?> &middot; All rights reserved</span>
                </div>
            </div>
            <div class="lp-footer-links">
                <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms</a>
                <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy</a>
                <a href="#" data-bs-toggle="modal" data-bs-target="#faqModal">FAQs</a>
            </div>
        </div>
    </div>
</footer>

<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="termsModalLabel"><i class="fas fa-file-contract me-2"></i>Terms and Conditions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6>1. Acceptance of Terms</h6>
                <p>By accessing and using the Ilocos Sur Community College Learning Management System (ISCC LMS), you agree to comply with and be bound by these Terms and Conditions. If you do not agree to these terms, please do not use the system.</p>
                <h6>2. User Accounts</h6>
                <p>Access to the LMS is provided exclusively to authorized students, faculty, and staff of Ilocos Sur Community College. Users are responsible for maintaining the confidentiality of their login credentials and for all activities that occur under their account.</p>
                <h6>3. Acceptable Use</h6>
                <p>Users shall use the LMS solely for educational purposes related to their enrollment or employment at ISCC. The following activities are prohibited:</p>
                <ul>
                    <li>Sharing login credentials with unauthorized individuals</li>
                    <li>Uploading or distributing inappropriate, offensive, or copyrighted content</li>
                    <li>Attempting to access accounts, data, or features without authorization</li>
                    <li>Using the system for any commercial or non-academic purposes</li>
                    <li>Interfering with the normal operation of the LMS</li>
                </ul>
                <h6>4. Academic Integrity</h6>
                <p>Users must adhere to ISCC's academic integrity policies when using the LMS. Plagiarism, cheating, and other forms of academic dishonesty are strictly prohibited and may result in disciplinary action.</p>
                <h6>5. Intellectual Property</h6>
                <p>All course materials, content, and resources available through the LMS are the intellectual property of ISCC and its faculty. Users may not reproduce, distribute, or share these materials outside of the LMS without written permission.</p>
                <h6>6. System Availability</h6>
                <p>ISCC strives to maintain the LMS available at all times but does not guarantee uninterrupted access. Scheduled maintenance and unforeseen technical issues may result in temporary unavailability.</p>
                <h6>7. Termination</h6>
                <p>ISCC reserves the right to suspend or terminate user access to the LMS for violations of these terms, institutional policies, or upon the user's separation from the institution.</p>
                <h6>8. Modifications</h6>
                <p>ISCC reserves the right to modify these Terms and Conditions at any time. Users will be notified of significant changes through the LMS or official communication channels.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I Understand</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="privacyModalLabel"><i class="fas fa-shield-alt me-2"></i>Privacy Policy</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6>1. Information We Collect</h6>
                <p>The ISCC LMS collects the following information necessary for providing educational services:</p>
                <ul>
                    <li>Personal information (name, student/employee ID, contact details)</li>
                    <li>Academic records (grades, course enrollments, quiz scores)</li>
                    <li>System usage data (login times, activity logs, IP addresses)</li>
                </ul>
                <h6>2. How We Use Your Information</h6>
                <p>Your information is used exclusively for:</p>
                <ul>
                    <li>Providing and improving educational services through the LMS</li>
                    <li>Tracking academic progress and generating reports</li>
                    <li>System administration and security monitoring</li>
                    <li>Communication regarding academic matters</li>
                </ul>
                <h6>3. Data Protection</h6>
                <p>ISCC implements appropriate technical and organizational measures to protect your personal data in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173) of the Philippines. This includes secure storage, encrypted connections, and access controls.</p>
                <h6>4. Data Sharing</h6>
                <p>Your personal data will not be shared with third parties except as required by law, regulation, or legitimate institutional purposes such as reporting to the Commission on Higher Education (CHED).</p>
                <h6>5. Data Retention</h6>
                <p>Academic records are retained in accordance with CHED regulations and ISCC's records management policy. System logs are retained for security and audit purposes for a reasonable period.</p>
                <h6>6. Your Rights</h6>
                <p>Under the Data Privacy Act of 2012, you have the right to access, correct, and request deletion of your personal data. For inquiries or requests regarding your data, contact the ISCC administration office.</p>
                <h6>7. Contact</h6>
                <p>For privacy-related concerns, please contact:<br>
                Ilocos Sur Community College<br>
                Quirino Stadium, Zone V, Bantay, Ilocos Sur<br>
                Email: isccbantay@yahoo.com<br>
                Phone: (077) 604-0285</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I Understand</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="faqModal" tabindex="-1" aria-labelledby="faqModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="faqModalLabel"><i class="fas fa-question-circle me-2"></i>Frequently Asked Questions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="accordion" id="faqAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">How do I access the LMS?</button></h2>
                        <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion"><div class="accordion-body">Click the "Sign In" button on this page. Enter the username and password provided to you by the ISCC administration or your instructor.</div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">I forgot my password. What should I do?</button></h2>
                        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body">Please contact your instructor or the ISCC administration office to have your password reset. You can reach them at (077) 604-0285 or email isccbantay@yahoo.com.</div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">What programs are available in the LMS?</button></h2>
                        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body">The LMS supports all degree programs offered at ISCC: Bachelor of Science in Agribusiness (BSAB), Information Technology (BSIT), Cooperative Management (BSCM), Hospitality Management (BSHM), Tourism Management (BSTM), and Midwifery (BSM).</div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">How does the Knowledge Tree work?</button></h2>
                        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body">The Knowledge Tree is a structured learning path where topics are organized by difficulty levels (Beginner, Intermediate, Advanced). You progress through nodes by completing lessons and quizzes. Locked nodes are unlocked as you complete prerequisite topics.</div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">How are badges earned?</button></h2>
                        <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body">Badges are awarded automatically when you reach certain milestones such as completing all lessons in a class, achieving high quiz scores, or finishing all knowledge tree nodes. Check the Badges section in your dashboard to see available and earned badges.</div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6">Who do I contact for technical issues?</button></h2>
                        <div id="faq6" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body">For technical issues with the LMS, please contact the ISCC IT support or administration office at (077) 604-0285 or email isccbantay@yahoo.com during office hours.</div></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', function(e) {
        const t = document.querySelector(this.getAttribute('href'));
        if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
});
window.addEventListener('scroll', function() {
    document.querySelector('.lp-topbar').classList.toggle('scrolled', window.scrollY > 30);
});
</script>
</body>
</html>
