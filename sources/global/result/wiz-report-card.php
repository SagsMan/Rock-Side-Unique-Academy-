<?php

/*
 * Rock-Side Unique Academy report card renderer.
 *
 * The result engine has already loaded the student, class, configured subjects,
 * scores, grades, remarks, attendance, conduct and signatures before this file
 * is included. This file is intentionally presentation-only.
 */

if (!defined('fobrain')) {
	die('Hahahaha, Hacking attempt . . . . Be Careful . . . . You Are Been Warned !!!!');
}

$curSess = currentSessionInfo($conn);
list($curSessID, $cSess) = explode("@$@", $curSess);

$classNum = studentClassCount($conn, $sessionID, $class, $level);
$next_begin = termStartDate($conn, $curSessID, $term);
$levelArray = studentLevelsArray($conn);
$student_level = $levelArray[$level - $fiVal]['level'];

$clArray = studentClassArray($conn, $level);
$classArray = unserialize($clArray);
$class_index = array_search($class, $class_list);
$class_m = $classArray[$class_index] ?? $class;

$examArray = schoolExamConfigArrays($conn);
$exam_status = (int)($examArray[0]['status'] ?? $thVal);
$exam_fi = $examArray[0]['fi_ass'] ?? 10;
$exam_se = $examArray[0]['se_ass'] ?? 10;
$exam_th = $examArray[0]['th_ass'] ?? 10;
$exam_fo = $examArray[0]['fo_ass'] ?? 10;
$exam_fif = $examArray[0]['fif_ass'] ?? 10;
$exam_six = $examArray[0]['six_ass'] ?? 10;
$exam_score = $examArray[0]['exam'] ?? 40;

$principalData = staffData($conn, $schoolHead);
list(
	$princ_title,
	$princ_fullname,
	$princ_sex,
	$princ_rankingVal,
	$princ_picture,
	$princ_lname,
	$princ_phone,
	$princ_sign
) = explode("#@s@#", $principalData);

$schoolPrincipal = ($title_list[$princ_title] ?? '') . ' ' . $princ_fullname;
$formTeacher = formTeacher($conn, $sessionID, $level, $class);
$formTeacherSign = formTeacherSignatures($conn, $sessionID, $level, $class);
$gradeArray = gradeDataArr($conn);

/* Load student information and the saved conduct/remarks for this term. */
$studentQuery = "SELECT r.nk_regno, f.$queryUserBio, c.$conducts_field
	FROM $i_reg_tb r
	INNER JOIN $i_student_tb f ON (r.ireg_id = f.ireg_id)
		AND r.session_id = :session_id
		AND r.$nk_class = :class
		AND r.active = :foreal
		AND r.nk_regno = :nk_regno
	INNER JOIN $sdoracle_student_remark_nk c ON (r.ireg_id = c.ireg_id)";

$studentStmt = $conn->prepare($studentQuery);
$studentStmt->bindValue(':nk_regno', $regNum, PDO::PARAM_STR);
$studentStmt->bindValue(':session_id', $sessionID, PDO::PARAM_STR);
$studentStmt->bindValue(':foreal', $foreal, PDO::PARAM_STR);
$studentStmt->bindValue(':class', $class, PDO::PARAM_STR);
$studentStmt->execute();

$studentRow = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: array();
$pic = $studentRow['i_stupic'] ?? '';
$fname = $studentRow['i_firstname'] ?? '';
$mname = $studentRow['i_midname'] ?? '';
$lname = $studentRow['i_lastname'] ?? '';
$attendance = $studentRow[$attendance_r] ?? '0,0,0';
$conducts = $studentRow[$conducts_r] ?? ',,,,,,,,,';
$i_sport = $studentRow[$sports_r] ?? ',,,,';
$comment = $studentRow[$comment_r] ?? '';
$ftRemark = $studentRow[$comment_t] ?? '';
$pr_comment = $studentRow[$pr_comment_r] ?? '';
$student_name = trim("$lname $mname $fname");

$gsRemarkArray = teacherRemarksArrays($conn);
$sportArray = sportsArrays($conn);
$student_img = picture($school_pic_dir . $session_fi . '_' . $session_se . '/', $pic, "student");

$attendanceParts = array_pad(explode(',', (string)$attendance), 3, '-');
$NOTSchOpen = $attendanceParts[0];
$NOTPresent = $attendanceParts[1];
$NOTPunc = $attendanceParts[2];
$NOTAbsent = max(0, (int)$NOTSchOpen - (int)$NOTPresent);

$conductParts = array_pad(explode(',', (string)$conducts), 10, '-');
list(
	$i_conduct_1,
	$i_conduct_2,
	$i_conduct_3,
	$i_conduct_4,
	$i_conduct_5,
	$i_conduct_6,
	$i_conduct_7,
	$i_conduct_8,
	$i_conduct_9,
	$i_conduct_10
) = $conductParts;

$sportParts = array_pad(explode(',', (string)$i_sport), 5, '');
$sportNames = array();
foreach ($sportParts as $sportID) {
	$sportID = (int)$sportID;
	if ($sportID && isset($sportArray[$sportID]['name'])) {
		$sportNames[] = $sportArray[$sportID]['name'];
	}
}

$gsRemark = '';
if ($comment !== '' && isset($gsRemarkArray[$comment]['name'])) {
	$gsRemark = $gsRemarkArray[$comment]['name'];
}

/*
 * The configured assessment status is also the number of continuous
 * assessment columns stored in the score string.
 */
$assessmentCount = $exam_status;
if ($assessmentCount < 1 || $assessmentCount > 6) {
	$assessmentCount = 3;
}

$assessmentMaximums = array($exam_fi, $exam_se, $exam_th, $exam_fo, $exam_fif, $exam_six);
$assessmentLabels = array('1st', '2nd', '3rd', '4th', '5th', '6th');

$academic_yr = recentAcademicYear($level, $session_fi);
$resultHeading = preg_match('/prep|grade|nursery|primary/i', "$student_level $class_m")
	? 'PREPARATORY / GRADE RESULT'
	: 'SECONDARY TERMLY RESULT';

$principalRemark = trim($pr_comment);
$subjectRows = array();
$subjectCount = 0;
$computedTotal = 0;
$failedSubjects = 0;
$total_score = '-';
$student_avg = '-';
$student_poistion = '-';

/* Keep the system-calculated total, average and class position when available. */
$grandScoreQuery = "SELECT r.nk_regno, f.$query_i_strings_nj
	FROM $i_reg_tb r
	INNER JOIN $sdoracle_grand_score_nk f ON (r.ireg_id = f.ireg_id)
		AND r.session_id = :session_id
		AND r.$nk_class = :class
		AND r.active = :foreal
		AND r.nk_regno = :nk_regno";

$grandScoreStmt = $conn->prepare($grandScoreQuery);
$grandScoreStmt->bindValue(':nk_regno', $regNum, PDO::PARAM_STR);
$grandScoreStmt->bindValue(':session_id', $sessionID, PDO::PARAM_STR);
$grandScoreStmt->bindValue(':foreal', $foreal, PDO::PARAM_STR);
$grandScoreStmt->bindValue(':class', $class, PDO::PARAM_STR);
$grandScoreStmt->execute();
$grandScoreRow = $grandScoreStmt->fetch(PDO::FETCH_NUM) ?: array();
$total_score = $grandScoreRow[1] ?? '-';
$student_avg = $grandScoreRow[2] ?? '-';
$student_poistion = $grandScoreRow[3] ?? '-';

/*
 * Retrieve the same score row used by the existing result engine. The
 * configured class subject array ($course_info_mark) controls which subjects
 * appear on this student's result.
 */
$scoreQuery = "SELECT r.nk_regno, f.$query_i_strings, g.$query_i_strings_nk, j.$query_i_scores
	FROM $i_reg_tb r
	INNER JOIN $sdoracle_sub_score_nk f ON (r.ireg_id = f.ireg_id)
		AND r.session_id = :session_id
		AND r.$nk_class = :class
		AND r.active = :foreal
		AND r.nk_regno = :nk_regno
	INNER JOIN $sdoracle_grade_nk g ON (r.ireg_id = g.ireg_id)
	INNER JOIN $sdoracle_score_nk j ON (g.ireg_id = j.ireg_id)";

$scoreStmt = $conn->prepare($scoreQuery);
$scoreStmt->bindValue(':nk_regno', $regNum, PDO::PARAM_STR);
$scoreStmt->bindValue(':session_id', $sessionID, PDO::PARAM_STR);
$scoreStmt->bindValue(':foreal', $foreal, PDO::PARAM_STR);
$scoreStmt->bindValue(':class', $class, PDO::PARAM_STR);
$scoreStmt->execute();

$scoreRow = $scoreStmt->fetch(PDO::FETCH_NUM) ?: array();
$scoreColumn = ($i_stop_loop * 2) + 2;
$positionColumn = $i_stop_loop + 2;
$subjectIndex = $start_nkiru;

for ($i = $i_start_loop; $i <= $i_stop_loop; $i++) {
	$score = $scoreRow[$i] ?? '';
	$score = trim((string)$score);
	$subjectName = $course_info_mark[$subjectIndex][2] ?? 'Subject';
	$scoreParts = array_pad(explode(',', (string)($scoreRow[$scoreColumn] ?? '')), $assessmentCount + 1, '-');
	$examMark = array_pop($scoreParts);

	if ($score !== '' && is_numeric($score) && (float)$score >= $fiVal) {
		$grade = fobrainGradeScore($gradeArray, $score);
		$remark = gradeRemarks($score);
		$position = studentPostionSup($scoreRow[$positionColumn] ?? '-');
		$computedTotal += (float)$score;
		if ((string)$grade === 'F') {
			$failedSubjects++;
		}
	} else {
		$score = '-';
		$grade = '-';
		$remark = '-';
		$position = '-';
		$scoreParts = array_fill(0, $assessmentCount, '-');
		$examMark = '-';
	}

	$subjectRows[] = array(
		'name' => $subjectName,
		'assessments' => array_slice($scoreParts, 0, $assessmentCount),
		'exam' => $examMark,
		'total' => $score,
		'position' => $position,
		'grade' => $grade,
		'remark' => $remark
	);
	$subjectCount++;
	$scoreColumn++;
	$positionColumn++;
	$subjectIndex++;
}

$studentAverage = isset($student_avg) && $student_avg !== '' ? $student_avg : (
	$subjectCount ? number_format($computedTotal / $subjectCount, 2) : '-'
);
$studentPosition = isset($student_poistion) ? studentPostionSup($student_poistion) : '-';
if ($principalRemark === '' || $principalRemark === '-') {
	$principalRemark = fobrainPrincipalRemarks($studentAverage, 0, 0, $failedSubjects);
}
$principalSignImage = picture($staff_doc_ext, $princ_sign, "sign");

$schoolNameEsc = htmlspecialchars((string)$schoolNameTop, ENT_QUOTES, 'UTF-8');
$schoolAddressEsc = htmlspecialchars((string)$schoolAddressTop, ENT_QUOTES, 'UTF-8');
$studentNameEsc = htmlspecialchars((string)$student_name, ENT_QUOTES, 'UTF-8');
$classEsc = htmlspecialchars((string)$class_m, ENT_QUOTES, 'UTF-8');
$levelEsc = htmlspecialchars((string)$student_level, ENT_QUOTES, 'UTF-8');
$termEsc = htmlspecialchars((string)$term_value, ENT_QUOTES, 'UTF-8');
$yearEsc = htmlspecialchars((string)$academic_yr, ENT_QUOTES, 'UTF-8');

?>

<style>
	.rockside-report-card {
		background: #fff;
		border: 3px solid #17346b;
		color: #111;
		font-family: "Times New Roman", Georgia, serif;
		font-size: 12px;
		margin: 0 auto;
		max-width: 980px;
		padding: 14px;
	}
	.rockside-report-card * { box-sizing: border-box; }
	.rockside-report-card .report-header {
		align-items: center;
		display: grid;
		grid-template-columns: 105px 1fr 105px;
		gap: 10px;
		text-align: center;
	}
	.rockside-report-card .report-logo {
		height: 92px;
		object-fit: contain;
		width: 92px;
	}
	.rockside-report-card .school-name {
		color: #123c86;
		font-family: Georgia, "Times New Roman", serif;
		font-size: 27px;
		font-weight: 700;
		line-height: 1.05;
		margin: 0;
		text-transform: uppercase;
	}
	.rockside-report-card .school-motto {
		color: #d81919;
		font-weight: 700;
		margin: 4px 0 2px;
	}
	.rockside-report-card .school-address {
		font-size: 14px;
		font-weight: 700;
		line-height: 1.15;
		margin: 0;
		text-transform: uppercase;
	}
	.rockside-report-card .result-heading {
		color: #d51c1c;
		font-size: 20px;
		font-weight: 700;
		margin: 8px 0 6px;
		text-align: center;
	}
	.rockside-report-card .result-heading.preparatory { color: #c51891; }
	.rockside-report-card table {
		border-collapse: collapse;
		width: 100%;
	}
	.rockside-report-card th,
	.rockside-report-card td {
		border: 1px solid #17346b;
		padding: 3px 4px;
		vertical-align: middle;
	}
	.rockside-report-card .section-title {
		background: #fff;
		font-weight: 700;
		text-align: center;
	}
	.rockside-report-card .identity td {
		height: 22px;
	}
	.rockside-report-card .identity strong { margin-right: 5px; }
	.rockside-report-card .subjects thead th {
		background: #f8faff;
		font-weight: 700;
		text-align: center;
	}
	.rockside-report-card .subjects thead .subject-label {
		color: #d51c1c;
		font-size: 20px;
	}
	.rockside-report-card .subjects td:not(:first-child),
	.rockside-report-card .subjects th:not(:first-child) {
		text-align: center;
	}
	.rockside-report-card .subjects td:first-child {
		font-weight: 600;
		white-space: nowrap;
	}
	.rockside-report-card .summary td {
		height: 25px;
	}
	.rockside-report-card .summary-label {
		font-weight: 700;
		text-align: right;
	}
	.rockside-report-card .line-value {
		display: inline-block;
		min-width: 115px;
	}
	.rockside-report-card .remarks td {
		height: 28px;
	}
	.rockside-report-card .lower-grid {
		display: grid;
		grid-template-columns: 1fr 1.25fr 1.5fr;
		margin-top: 6px;
	}
	.rockside-report-card .lower-grid > table {
		height: 100%;
	}
	.rockside-report-card .lower-grid td,
	.rockside-report-card .lower-grid th {
		height: 22px;
	}
	.rockside-report-card .rating-key td {
		border: 0;
		padding: 1px 3px;
	}
	.rockside-report-card .rating-key th,
	.rockside-report-card .attendance th,
	.rockside-report-card .conduct th {
		text-align: center;
	}
	.rockside-report-card .conduct td:not(:first-child) { text-align: center; }
	.rockside-report-card .footer-motto {
		color: #d51c1c;
		display: flex;
		font-size: 13px;
		font-style: italic;
		font-weight: 700;
		justify-content: space-between;
		padding: 8px 12px 0;
	}
	@media (max-width: 700px) {
		.rockside-report-card { font-size: 9px; padding: 5px; }
		.rockside-report-card .report-header { grid-template-columns: 50px 1fr 50px; gap: 3px; }
		.rockside-report-card .report-logo { height: 46px; width: 46px; }
		.rockside-report-card .school-name { font-size: 15px; }
		.rockside-report-card .school-address { font-size: 8px; }
		.rockside-report-card .result-heading { font-size: 13px; }
		.rockside-report-card .subjects td:first-child { white-space: normal; }
		.rockside-report-card .lower-grid { grid-template-columns: 1fr; }
	}
	@media print {
		.rockside-report-card {
			border: 2px solid #17346b;
			max-width: none;
			width: 100%;
		}
		.rockside-report-card .subjects { page-break-inside: auto; }
		.rockside-report-card tr { page-break-inside: avoid; }
	}
</style>

<div class="rockside-report-card">
	<div class="report-header">
		<img class="report-logo" src="<?php echo $sch_logo; ?>" alt="School logo">
		<div>
			<h1 class="school-name"><?php echo $schoolNameEsc; ?></h1>
			<div class="school-motto">Motto: Quest for Excellence...</div>
			<p class="school-address"><?php echo $schoolAddressEsc; ?></p>
			<p class="school-address">07065757907, 09033665705, 08023364447</p>
		</div>
		<img class="report-logo" src="<?php echo $sch_logo; ?>" alt="School emblem">
	</div>

	<div class="result-heading <?php echo strpos($resultHeading, 'PREPARATORY') !== false ? 'preparatory' : ''; ?>">
		<?php echo $resultHeading; ?>
	</div>

	<table class="identity">
		<tr><th class="section-title" colspan="<?php echo $assessmentCount + 6; ?>">Academic Cognitive Performance</th></tr>
		<tr>
			<td colspan="<?php echo $assessmentCount + 2; ?>"><strong>Student's Name:</strong> <?php echo $studentNameEsc; ?></td>
			<td colspan="4"><strong>Registration No:</strong> <?php echo htmlspecialchars((string)$regNum, ENT_QUOTES, 'UTF-8'); ?></td>
		</tr>
		<tr>
			<td colspan="<?php echo $assessmentCount + 2; ?>"><strong>Class:</strong> <?php echo $classEsc; ?></td>
			<td colspan="4"><strong>Term:</strong> <?php echo $termEsc; ?> &nbsp; <strong>Session:</strong> <?php echo $yearEsc; ?></td>
		</tr>
	</table>

	<table class="subjects">
		<thead>
			<tr>
				<th class="subject-label" rowspan="2">Subjects</th>
				<th colspan="<?php echo $assessmentCount; ?>">Ass.</th>
				<th rowspan="2">Exam<br>(<?php echo htmlspecialchars((string)$exam_score, ENT_QUOTES, 'UTF-8'); ?>)</th>
				<th rowspan="2">Total<br>(100)</th>
				<th rowspan="2">Position</th>
				<th rowspan="2">Grade</th>
				<th rowspan="2">Remark</th>
			</tr>
			<tr>
				<?php for ($i = 0; $i < $assessmentCount; $i++) { ?>
					<th><?php echo $assessmentLabels[$i]; ?><br>(<?php echo htmlspecialchars((string)$assessmentMaximums[$i], ENT_QUOTES, 'UTF-8'); ?>)</th>
				<?php } ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($subjectRows as $subject) { ?>
				<tr>
					<td><?php echo htmlspecialchars((string)$subject['name'], ENT_QUOTES, 'UTF-8'); ?></td>
					<?php foreach ($subject['assessments'] as $assessment) { ?>
						<td><?php echo htmlspecialchars((string)$assessment, ENT_QUOTES, 'UTF-8'); ?></td>
					<?php } ?>
					<td><?php echo htmlspecialchars((string)$subject['exam'], ENT_QUOTES, 'UTF-8'); ?></td>
					<td><?php echo htmlspecialchars((string)$subject['total'], ENT_QUOTES, 'UTF-8'); ?></td>
					<td><?php echo htmlspecialchars((string)$subject['position'], ENT_QUOTES, 'UTF-8'); ?></td>
					<td><?php echo htmlspecialchars((string)$subject['grade'], ENT_QUOTES, 'UTF-8'); ?></td>
					<td><?php echo htmlspecialchars((string)$subject['remark'], ENT_QUOTES, 'UTF-8'); ?></td>
				</tr>
			<?php } ?>
		</tbody>
	</table>

	<table class="summary">
		<tr>
			<td><span class="summary-label">Total:</span> <span class="line-value"><?php echo htmlspecialchars((string)$total_score, ENT_QUOTES, 'UTF-8'); ?></span></td>
			<td><span class="summary-label">Average:</span> <span class="line-value"><?php echo htmlspecialchars((string)$studentAverage, ENT_QUOTES, 'UTF-8'); ?></span></td>
		</tr>
		<tr>
			<td><span class="summary-label">Promoted:</span> <span class="line-value"></span></td>
			<td><span class="summary-label">School Fees Owed:</span> <span class="line-value"></span></td>
		</tr>
		<tr>
			<td><span class="summary-label">Not Promoted:</span> <span class="line-value"></span></td>
			<td><span class="summary-label">School Fees Paid:</span> <span class="line-value"></span></td>
		</tr>
	</table>

	<table class="remarks">
		<tr>
			<td>Class Teacher's Remark: <?php echo htmlspecialchars((string)$ftRemark, ENT_QUOTES, 'UTF-8'); ?></td>
			<td>Head Teacher's Remark: <?php echo htmlspecialchars((string)$principalRemark, ENT_QUOTES, 'UTF-8'); ?></td>
		</tr>
		<tr>
			<td>Class Teacher's Sign/Date: <?php echo $formTeacherSign; ?></td>
			<td>Position: <?php echo htmlspecialchars((string)$studentPosition, ENT_QUOTES, 'UTF-8'); ?></td>
		</tr>
		<tr>
			<td>Head Teacher's Sign/Date: <?php echo $principalSignImage ? '<img src="' . $principalSignImage . '" alt="Head teacher signature" style="height:28px;max-width:110px;">' : ''; ?></td>
			<td>Term Ended: ____________________</td>
		</tr>
		<tr>
			<td>Sports Master's Sign/Date: ____________________</td>
			<td>Next Term Begins: <?php echo htmlspecialchars((string)$next_begin, ENT_QUOTES, 'UTF-8'); ?></td>
		</tr>
	</table>

	<div class="lower-grid">
		<table class="rating-key">
			<tr><th colspan="2">KEY TO RATINGS</th></tr>
			<tr><td>A</td><td>Excellent</td></tr>
			<tr><td>B</td><td>Very Good</td></tr>
			<tr><td>C</td><td>Good</td></tr>
			<tr><td>D</td><td>Fair</td></tr>
			<tr><td>E</td><td>Poor</td></tr>
			<tr><td>F</td><td>Fail</td></tr>
		</table>

		<table class="attendance">
			<tr><th colspan="2">ATTENDANCE</th></tr>
			<tr><td>No. of times school opened</td><td><?php echo htmlspecialchars((string)$NOTSchOpen, ENT_QUOTES, 'UTF-8'); ?></td></tr>
			<tr><td>No. of times present</td><td><?php echo htmlspecialchars((string)$NOTPresent, ENT_QUOTES, 'UTF-8'); ?></td></tr>
			<tr><td>No. of times absent</td><td><?php echo htmlspecialchars((string)$NOTAbsent, ENT_QUOTES, 'UTF-8'); ?></td></tr>
			<tr><td>No. of times punctual</td><td><?php echo htmlspecialchars((string)$NOTPunc, ENT_QUOTES, 'UTF-8'); ?></td></tr>
		</table>

		<table class="conduct">
			<tr><th colspan="2">CONDUCT</th><th colspan="5">RATING</th></tr>
			<tr><td>Punctuality</td><td colspan="6"><?php echo htmlspecialchars((string)$i_conduct_1, ENT_QUOTES, 'UTF-8'); ?></td></tr>
			<tr><td>Neatness</td><td colspan="6"><?php echo htmlspecialchars((string)$i_conduct_2, ENT_QUOTES, 'UTF-8'); ?></td></tr>
			<tr><td>Attendance</td><td colspan="6"><?php echo htmlspecialchars((string)$i_conduct_3, ENT_QUOTES, 'UTF-8'); ?></td></tr>
			<tr><td>Honesty</td><td colspan="6"><?php echo htmlspecialchars((string)$i_conduct_4, ENT_QUOTES, 'UTF-8'); ?></td></tr>
			<tr><td>Carrying out Assignment</td><td colspan="6"><?php echo htmlspecialchars((string)$i_conduct_5, ENT_QUOTES, 'UTF-8'); ?></td></tr>
		</table>
	</div>

	<div class="footer-motto">
		<span>*Moral</span>
		<span>*Integrity</span>
		<span>*Academic Excellence</span>
	</div>
</div>