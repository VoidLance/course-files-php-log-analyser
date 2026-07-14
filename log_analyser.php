<?php
// I keep uploaded files in session so I can filter without re-uploading every time.
session_start();

// Session keys in one place so I do not mistype them later.
$sessionLogContentKey = 'log_analyser_content';
$sessionLogNameKey = 'log_analyser_name';

// Tiny helper so user input does not break HTML.
function escape($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Parse text logs using the requested regex approach.
function parseTextLogs($logContent)
{
	$entries = [];
	$pattern = '/^\[(\d{4}-\d{2}-\d{2})\s(\d{2}:\d{2}:\d{2})\]\s\[([A-Z_]+)\]\s\[([^\]]+)\]\s(.+)$/m';
	$matchCount = preg_match_all($pattern, $logContent, $matches, PREG_SET_ORDER);

	if ($matchCount === false || $matchCount === 0) {
		return $entries;
	}

	foreach ($matches as $match) {
		$entries[] = [
			'date' => $match[1],
			'time' => $match[2],
			'level' => strtoupper(trim($match[3])),
			'source' => trim($match[4]),
			'message' => trim($match[5]),
		];
	}

	return $entries;
}

// Parse JSON logs. Returns entries + error message.
function parseJsonLogs($logContent)
{
	$entries = [];
	$error = '';

	$decoded = json_decode($logContent, true);

	if (json_last_error() !== JSON_ERROR_NONE) {
		return [
			'entries' => [],
			'error' => 'The uploaded JSON file is not valid JSON.',
		];
	}

	if (is_array($decoded) && array_is_list($decoded)) {
		$items = $decoded;
	} elseif (is_array($decoded)) {
		$items = [$decoded];
	} else {
		return [
			'entries' => [],
			'error' => 'JSON log files must contain one object or an array of objects.',
		];
	}

	foreach ($items as $item) {
		if (!is_array($item)) {
			$error = 'Each JSON log entry must be an object with timestamp, level, source, and message.';
			break;
		}

		$timestamp = isset($item['timestamp']) ? trim((string) $item['timestamp']) : '';
		$level = isset($item['level']) ? strtoupper(trim((string) $item['level'])) : '';
		$source = isset($item['source']) ? trim((string) $item['source']) : '';
		$message = isset($item['message']) ? trim((string) $item['message']) : '';

		$timeMatches = [];
		$validTime = preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/', $timestamp, $timeMatches);

		if ($validTime !== 1 || $level === '' || $source === '' || $message === '') {
			$error = 'Each JSON log entry must include timestamp, level, source, and message in the expected format.';
			break;
		}

		$entries[] = [
			'date' => $timeMatches[1],
			'time' => $timeMatches[2],
			'level' => $level,
			'source' => $source,
			'message' => $message,
		];
	}

	if ($error !== '') {
		return [
			'entries' => [],
			'error' => $error,
		];
	}

	return [
		'entries' => $entries,
		'error' => '',
	];
}

// Decide whether to parse as text logs or JSON logs.
function parseLogs($logContent, $fileName)
{
	$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

	if ($extension === 'json') {
		$jsonResult = parseJsonLogs($logContent);

		return [
			'entries' => $jsonResult['entries'],
			'error' => $jsonResult['error'],
			'format' => 'json',
		];
	}

	return [
		'entries' => parseTextLogs($logContent),
		'error' => '',
		'format' => 'text',
	];
}

// Count log levels for the summary cards.
function countLevels($entries)
{
	$counts = [];

	foreach ($entries as $entry) {
		$level = $entry['level'];
		if (!isset($counts[$level])) {
			$counts[$level] = 0;
		}
		$counts[$level]++;
	}

	ksort($counts);
	return $counts;
}

// Get unique sources for the source dropdown.
function getSources($entries)
{
	$sources = [];

	foreach ($entries as $entry) {
		$sources[] = $entry['source'];
	}

	$sources = array_values(array_unique($sources));
	natcasesort($sources);

	return array_values($sources);
}

// Apply all filters in one place (date, level, source, search text).
function filterEntries($entries, $startDate, $endDate, $selectedLevel, $selectedSource, $searchTerm)
{
	$filtered = [];
	$selectedLevel = strtoupper(trim($selectedLevel));
	$selectedSource = trim($selectedSource);
	$searchTerm = trim($searchTerm);
	$sourcePattern = $selectedSource === '' ? '' : '/^' . preg_quote($selectedSource, '/') . '$/i';
	$messagePattern = $searchTerm === '' ? '' : '/' . preg_quote($searchTerm, '/') . '/i';

	foreach ($entries as $entry) {
		if ($startDate !== '' && $entry['date'] < $startDate) {
			continue;
		}

		if ($endDate !== '' && $entry['date'] > $endDate) {
			continue;
		}

		if ($selectedLevel !== '' && $entry['level'] !== $selectedLevel) {
			continue;
		}

		if ($sourcePattern !== '' && preg_match($sourcePattern, $entry['source']) !== 1) {
			continue;
		}

		if ($messagePattern !== '' && preg_match($messagePattern, $entry['message']) !== 1) {
			continue;
		}

		$filtered[] = $entry;
	}

	return $filtered;
}

// Read uploaded file content safely.
function readUploadedFile()
{
	if (!isset($_FILES['log_file']) || !is_array($_FILES['log_file'])) {
		return null;
	}

	$tmpName = $_FILES['log_file']['tmp_name'] ?? '';
	$error = $_FILES['log_file']['error'] ?? UPLOAD_ERR_NO_FILE;

	if ($error !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
		return null;
	}

	$content = file_get_contents($tmpName);
	if ($content === false) {
		return null;
	}

	return $content;
}

$errors = [];
$statusMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_POST['clear_log'])) {
		unset($_SESSION[$sessionLogContentKey], $_SESSION[$sessionLogNameKey]);
		$statusMessage = 'Cleared the uploaded log file.';
	} else {
		$uploadedContent = readUploadedFile();

		if ($uploadedContent !== null) {
			$_SESSION[$sessionLogContentKey] = $uploadedContent;
			$_SESSION[$sessionLogNameKey] = isset($_FILES['log_file']['name']) ? (string) $_FILES['log_file']['name'] : 'uploaded.log';
			$statusMessage = 'Loaded log file successfully.';
		} elseif (isset($_FILES['log_file']) && (int) ($_FILES['log_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
			$errors[] = 'The log file could not be uploaded.';
		}
	}
}

$logContent = isset($_SESSION[$sessionLogContentKey]) ? (string) $_SESSION[$sessionLogContentKey] : '';
$logFileName = isset($_SESSION[$sessionLogNameKey]) ? (string) $_SESSION[$sessionLogNameKey] : '';

$parsedLog = [
	'entries' => [],
	'error' => '',
	'format' => 'text',
];

if ($logContent !== '') {
	$parsedLog = parseLogs($logContent, $logFileName);
}

$entries = $parsedLog['entries'];
$summary = countLevels($entries);
$sources = getSources($entries);

$startDate = isset($_POST['start_date']) ? trim((string) $_POST['start_date']) : '';
$endDate = isset($_POST['end_date']) ? trim((string) $_POST['end_date']) : '';
$selectedLevel = isset($_POST['log_level']) ? trim((string) $_POST['log_level']) : '';
$selectedSource = isset($_POST['source']) ? trim((string) $_POST['source']) : '';
$searchTerm = isset($_POST['search_term']) ? trim((string) $_POST['search_term']) : '';

$filteredEntries = filterEntries($entries, $startDate, $endDate, $selectedLevel, $selectedSource, $searchTerm);

$totalLines = 0;
if ($logContent !== '') {
	$lineMatches = preg_match_all('/^.*$/m', trim($logContent), $unusedMatches);
	$totalLines = $lineMatches === false ? 0 : $lineMatches;

	if ($parsedLog['error'] !== '') {
		$errors[] = $parsedLog['error'];
	} elseif ($totalLines > 0 && count($entries) === 0) {
		if ($parsedLog['format'] === 'json') {
			$errors[] = 'No valid JSON log entries were found in the uploaded file.';
		} else {
			$errors[] = 'No valid text log entries matched the expected pattern.';
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Log Analyzer</title>
	<style>
		/* Basic page colors so the project feels clean, but still student-built. */
		:root {
			--page-bg: #eef2f7;
			--panel-bg: #ffffff;
			--panel-border: #cfd8e3;
			--text-main: #243447;
			--text-soft: #5b6b7c;
			--primary: #2d6cdf;
			--primary-dark: #1f57bb;
			--secondary: #f2f5f9;
			--success-bg: #e8f6ed;
			--success-text: #1f7a47;
			--error-bg: #fdeaea;
			--error-text: #9a2f2f;
			--badge-bg: #dce8ff;
			--badge-text: #214f9c;
		}

		/* Universal box sizing saves me from CSS math headaches. */
		* {
			box-sizing: border-box;
		}

		/* Keep the page centered and easy to read. */
		body {
			margin: 0;
			font-family: Verdana, Geneva, Tahoma, sans-serif;
			background: var(--page-bg);
			color: var(--text-main);
		}

		/* Main wrapper keeps the content from stretching into the void. */
		.page {
			width: min(1100px, calc(100% - 2rem));
			margin: 1.5rem auto 2rem;
		}

		/* Panels all share the same simple card look. */
		.hero,
		.panel {
			background: var(--panel-bg);
			border: 1px solid var(--panel-border);
			border-radius: 12px;
			box-shadow: 0 2px 8px rgba(25, 40, 60, 0.08);
		}

		/* Top section introduces the tool. */
		.hero {
			padding: 1.5rem;
			margin-bottom: 1rem;
		}

		/* Keep headings simple and readable. */
		h1,
		h2 {
			margin: 0;
		}

		/* Main page title gets a little more visual weight. */
		h1 {
			font-size: 2rem;
			margin-bottom: 0.5rem;
		}

		/* Section titles are smaller but still clear. */
		h2 {
			font-size: 1.2rem;
			margin-bottom: 0.9rem;
		}

		/* Paragraph text uses the softer color for contrast. */
		p {
			margin: 0;
			color: var(--text-soft);
			line-height: 1.5;
		}

		/* Two-column layout on larger screens. */
		.layout {
			display: grid;
			grid-template-columns: 320px 1fr;
			gap: 1rem;
		}

		/* Card padding keeps content from hugging the walls. */
		.panel {
			padding: 1.25rem;
		}

		/* Stack helper for form sections. */
		.stack {
			display: grid;
			gap: 0.9rem;
		}

		/* Labels should be obvious and a little bold. */
		label {
			display: block;
			margin-bottom: 0.35rem;
			font-size: 0.95rem;
			font-weight: 700;
		}

		/* Inputs, selects, and buttons all use the same sizing rules. */
		input,
		select,
		button {
			width: 100%;
			padding: 0.75rem 0.85rem;
			font: inherit;
			border-radius: 8px;
		}

		/* Inputs and selects get simple borders. */
		input,
		select {
			border: 1px solid var(--panel-border);
			background: #fff;
			color: var(--text-main);
		}

		/* Focus state makes keyboard navigation easier to follow. */
		input:focus,
		select:focus {
			outline: 2px solid rgba(45, 108, 223, 0.2);
			border-color: var(--primary);
		}

		/* Primary buttons get the obvious action color. */
		button {
			border: 1px solid var(--primary);
			background: var(--primary);
			color: #fff;
			cursor: pointer;
			font-weight: 700;
		}

		/* Hover gives a little feedback without getting fancy. */
		button:hover {
			background: var(--primary-dark);
		}

		/* Secondary button looks less urgent. */
		.button-secondary {
			background: var(--secondary);
			color: var(--text-main);
			border-color: var(--panel-border);
		}

		/* Slight hover change for the secondary button too. */
		.button-secondary:hover {
			background: #e5ebf3;
		}

		/* Reusable grid groups for buttons and summary cards. */
		.button-row,
		.summary-grid {
			display: grid;
			gap: 0.75rem;
		}

		/* Two buttons per row when space allows it. */
		.button-row {
			grid-template-columns: 1fr 1fr;
		}

		/* Summary cards spread nicely across the page. */
		.summary-grid {
			grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
			margin-bottom: 1rem;
		}

		/* Each summary card is just a basic highlighted box. */
		.summary-card {
			padding: 0.9rem;
			border: 1px solid var(--panel-border);
			border-radius: 10px;
			background: var(--secondary);
		}

		/* Big number first so the summary is easy to scan. */
		.summary-card strong {
			display: block;
			font-size: 1.4rem;
			margin-bottom: 0.2rem;
		}

		/* Meta info row wraps nicely on smaller screens. */
		.meta {
			display: flex;
			flex-wrap: wrap;
			gap: 0.75rem;
			margin: 0 0 1rem;
			color: var(--text-soft);
			font-size: 0.95rem;
		}

		/* Shared spacing and rounding for notice boxes. */
		.status,
		.error,
		.hint {
			border-radius: 8px;
			padding: 0.85rem 1rem;
			margin-bottom: 1rem;
		}

		/* Success messages get the classic green treatment. */
		.status {
			background: var(--success-bg);
			color: var(--success-text);
			border: 1px solid #b7e0c4;
		}

		/* Error messages get a calm red box. */
		.error {
			background: var(--error-bg);
			color: var(--error-text);
			border: 1px solid #efc5c5;
		}

		/* Hint box explains the supported formats. */
		.hint {
			background: #f7f9fc;
			border: 1px solid var(--panel-border);
			color: var(--text-soft);
		}

		/* Table wrapper prevents sideways chaos on small screens. */
		.table-wrap {
			overflow-x: auto;
			border: 1px solid var(--panel-border);
			border-radius: 10px;
		}

		/* Standard full-width table setup. */
		table {
			width: 100%;
			border-collapse: collapse;
			background: #fff;
		}

		/* Cell spacing keeps the data readable. */
		th,
		td {
			padding: 0.85rem;
			text-align: left;
			border-bottom: 1px solid #e6ecf2;
			vertical-align: top;
		}

		/* Header row gets a subtle contrast boost. */
		th {
			background: #f2f5f9;
			font-size: 0.85rem;
			text-transform: uppercase;
			letter-spacing: 0.04em;
		}

		/* Zebra striping makes long tables easier to follow. */
		tr:nth-child(even) td {
			background: #fbfcfe;
		}

		/* Badge makes log level stand out a little. */
		.badge {
			display: inline-block;
			padding: 0.25rem 0.55rem;
			border-radius: 999px;
			background: var(--badge-bg);
			color: var(--badge-text);
			font-size: 0.8rem;
			font-weight: 700;
		}

		/* Empty state gives the user a nudge instead of a blank void. */
		.empty-state {
			padding: 1.2rem;
			text-align: center;
			color: var(--text-soft);
		}

		/* Code samples inside the hint box should look code-y. */
		code {
			font-family: "Courier New", Courier, monospace;
		}

		/* On smaller screens, stack everything into one column. */
		@media (max-width: 800px) {
			.layout {
				grid-template-columns: 1fr;
			}

			.button-row {
				grid-template-columns: 1fr;
			}
		}
	</style>
</head>
<body>
	<!-- Main wrapper for the whole app. -->
	<main class="page">
		<!-- Intro section so the user knows what this thing does. -->
		<section class="hero">
			<!-- Main title for the project. -->
			<h1>PHP Log Analyzer</h1>
			<!-- Short explanation of the tool in normal human words. -->
			<p>I built this page to upload log files, sort through them, and find the important stuff without reading every line by hand like a very tired detective.</p>
			<!-- Supported format guide lives here. -->
			<div class="hint">
				<!-- Text log example for the regex parser. -->
				Text format: <code>[2026-05-10 14:20:33] [ERROR] [Auth] Invalid credentials</code><br>
				<!-- JSON example for the JSON parser. -->
				JSON format: <code>{"timestamp":"2026-05-10 14:20:33","level":"ERROR","source":"Auth","message":"Invalid credentials"}</code>
			</div>
		</section>

		<!-- Main content splits into controls on the left and results on the right. -->
		<section class="layout">
			<!-- Sidebar holds upload and filter controls. -->
			<aside class="panel stack">
				<!-- Upload area starts here. -->
				<div>
					<!-- Section heading for the uploader. -->
					<h2>Upload Log File</h2>
					<!-- enctype is required for file uploads or PHP gets sad. -->
					<form method="post" enctype="multipart/form-data" class="stack">
						<!-- File picker input group. -->
						<div>
							<!-- Label tells the user what file types work. -->
							<label for="log_file">Choose a .log, .txt, or .json file</label>
							<!-- accept helps the file dialog suggest the right kinds of files. -->
							<input type="file" id="log_file" name="log_file" accept=".log,.txt,.json,text/plain">
						</div>
						<!-- Upload and clear buttons share this row. -->
						<div class="button-row">
							<!-- Submit uploads the selected file. -->
							<button type="submit">Upload File</button>
							<!-- This button clears the saved file from the session. -->
							<button type="submit" name="clear_log" value="1" class="button-secondary">Clear File</button>
						</div>
					</form>
				</div>

				<!-- Filter controls start here. -->
				<div>
					<!-- Section heading for filters. -->
					<h2>Filter Entries</h2>
					<!-- This form posts the chosen filter values back to the same page. -->
					<form method="post" class="stack">
						<!-- Start date field. -->
						<div>
							<label for="start_date">Start Date</label>
							<input type="date" id="start_date" name="start_date" value="<?= escape($startDate) ?>">
						</div>
						<!-- End date field. -->
						<div>
							<label for="end_date">End Date</label>
							<input type="date" id="end_date" name="end_date" value="<?= escape($endDate) ?>">
						</div>
						<!-- Log level dropdown. -->
						<div>
							<label for="log_level">Log Level</label>
							<select id="log_level" name="log_level">
								<!-- Default option keeps all levels visible. -->
								<option value="">All levels</option>
								<!-- Build one option per detected level. -->
								<?php foreach (array_keys($summary) as $level): ?>
									<!-- selected keeps the user's choice after submitting the form. -->
									<option value="<?= escape($level) ?>" <?= $selectedLevel === $level ? 'selected' : '' ?>><?= escape($level) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<!-- Source dropdown. -->
						<div>
							<label for="source">Source</label>
							<select id="source" name="source">
								<!-- Default option keeps all sources visible. -->
								<option value="">All sources</option>
								<!-- Build one option per detected source. -->
								<?php foreach ($sources as $source): ?>
									<!-- selected keeps the user's source choice too. -->
									<option value="<?= escape($source) ?>" <?= $selectedSource === $source ? 'selected' : '' ?>><?= escape($source) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<!-- Message search box. -->
						<div>
							<label for="search_term">Search Message Text</label>
							<input type="search" id="search_term" name="search_term" value="<?= escape($searchTerm) ?>" placeholder="error, timeout, login...">
						</div>
						<!-- Filter action buttons. -->
						<div class="button-row">
							<!-- Submit applies the chosen filters. -->
							<button type="submit">Apply Filters</button>
							<!-- Reset clears the form fields in the browser. -->
							<button type="reset" class="button-secondary">Reset Form</button>
						</div>
					</form>
				</div>
			</aside>

			<!-- Main results panel starts here. -->
			<section class="panel">
				<!-- Heading for the output area. -->
				<h2>Analysis Results</h2>

				<!-- Show a success-style message when something useful happened. -->
				<?php if ($statusMessage !== ''): ?>
					<div class="status"><?= escape($statusMessage) ?></div>
				<?php endif; ?>

				<!-- Show each parser or upload error as its own notice. -->
				<?php foreach ($errors as $error): ?>
					<div class="error"><?= escape($error) ?></div>
				<?php endforeach; ?>

				<!-- Quick stats row gives context before the table. -->
				<div class="meta">
					<!-- File name helps confirm what the user uploaded. -->
					<span>File: <strong><?= $logFileName !== '' ? escape($logFileName) : 'No file loaded' ?></strong></span>
					<!-- Total parsed entries before filtering. -->
					<span>Total parsed entries: <strong><?= count($entries) ?></strong></span>
					<!-- Entries left after filters are applied. -->
					<span>Filtered entries: <strong><?= count($filteredEntries) ?></strong></span>
					<!-- Raw line count from the uploaded file. -->
					<span>Matched lines: <strong><?= $totalLines ?></strong></span>
				</div>

				<!-- Summary cards show log level totals. -->
				<div class="summary-grid">
					<!-- If no entries exist yet, show a simple empty summary card. -->
					<?php if ($summary === []): ?>
						<div class="summary-card">
							<strong>0</strong>
							<span>No log levels yet</span>
						</div>
					<?php else: ?>
						<!-- Otherwise print one summary card per level. -->
						<?php foreach ($summary as $level => $count): ?>
							<div class="summary-card">
								<strong><?= $count ?></strong>
								<span><?= escape($level) ?></span>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<!-- Table wrapper handles overflow on small screens. -->
				<div class="table-wrap">
					<!-- Main results table. -->
					<table>
						<!-- Table headings explain each column. -->
						<thead>
							<tr>
								<th>Date</th>
								<th>Time</th>
								<th>Level</th>
								<th>Source</th>
								<th>Message</th>
							</tr>
						</thead>
						<!-- Table body prints either rows or an empty state. -->
						<tbody>
							<!-- If no entries survive, show a helpful placeholder row. -->
							<?php if ($filteredEntries === []): ?>
								<tr>
									<td colspan="5" class="empty-state">Upload a log file or adjust the filters to see parsed log entries.</td>
								</tr>
							<?php else: ?>
								<!-- Print one row per filtered log entry. -->
								<?php foreach ($filteredEntries as $entry): ?>
									<tr>
										<!-- Date column. -->
										<td><?= escape($entry['date']) ?></td>
										<!-- Time column. -->
										<td><?= escape($entry['time']) ?></td>
										<!-- Level column with badge styling. -->
										<td><span class="badge"><?= escape($entry['level']) ?></span></td>
										<!-- Source column. -->
										<td><?= escape($entry['source']) ?></td>
										<!-- Message column. -->
										<td><?= escape($entry['message']) ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</section>
		</section>
	</main>
</body>
</html>
