# PHP Log Analyzer

A small, dependency-free PHP web app for uploading log files and reviewing their entries in a browser. It parses supported text or JSON logs, summarizes entries by level, and lets you narrow results without scanning the file by hand.

## Features

- Upload `.log`, `.txt`, or `.json` files.
- View parsed date, time, level, source, and message fields.
- See counts for each log level, along with total and filtered entry counts.
- Filter by inclusive start and end dates, log level, and source.
- Search message text without case sensitivity.
- Keep the uploaded file in the current browser session while filtering, or clear it when finished.
- Use a responsive interface that works on smaller screens.

## Requirements

- PHP 8.1 or later, with sessions enabled.
- A web server capable of running PHP. No Composer packages or other dependencies are required.

## Get started

Clone the repository and start PHP's built-in development server from the project directory:

```sh
git clone https://github.com/VoidLance/course-files-php-log-analyser.git
cd course-files-php-log-analyser
php -S 127.0.0.1:8000
```

Open [http://127.0.0.1:8000/log_analyser.php](http://127.0.0.1:8000/log_analyser.php) in your browser. Choose a supported file and select **Upload File**. The included [`sample_log.json`](sample_log.json) is ready to try.

After uploading, use the date, level, source, and message search fields and choose **Apply Filters**. Select **Reset Form** to clear the filter fields, or **Clear File** to remove the uploaded log from the session.

## Supported log formats

Files ending in `.json` must contain a single log object or an array of log objects. Each entry requires `timestamp`, `level`, `source`, and `message`. Timestamps use `YYYY-MM-DD HH:MM:SS` or `YYYY-MM-DDTHH:MM:SS`.

```json
[
  {
    "timestamp": "2026-05-10 14:20:33",
    "level": "ERROR",
    "source": "Auth",
    "message": "Invalid credentials"
  }
]
```

Other file extensions are read as text logs, one entry per line, in this format:

```text
[2026-05-10 14:20:33] [ERROR] [Auth] Invalid credentials
```

The level is uppercase letters and underscores (for example, `INFO`, `WARNING`, or `ERROR`). The date, time, and source are required.

## Help

For a bug report, question, or feature request, [open an issue](https://github.com/VoidLance/course-files-php-log-analyser/issues). The application behavior and supported formats are also described above and in [`log_analyser.php`](log_analyser.php).

## Maintainers and contributing

The repository is maintained by [VoidLance](https://github.com/VoidLance), and contributions are welcome. There is no separate contribution guide at present; for substantial changes, open an issue first to discuss the proposal, then submit a pull request with a clear summary.

## License

No license file is currently included in this repository. Check with the repository owner before redistributing or reusing the project.
