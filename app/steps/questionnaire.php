<?php
// 2026-05-28: the questionnaire step was merged into exploratory.php
// (willingness moved there; interest and general_feedback removed). This
// stub redirects any straggler session that still lands here.
header('Location: ?step=debrief');
exit;
