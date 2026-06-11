<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign-in Error</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            background: #f3f2f1;
        }
        .card {
            background: #fff;
            border-radius: 8px;
            padding: 32px 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,.12);
            text-align: center;
            max-width: 400px;
        }
        .icon { font-size: 32px; margin-bottom: 16px; }
        p { color: #605e5c; font-size: 14px; margin: 0; line-height: 1.5; }
    </style>
</head>
<body>
<div class="card">
    <div class="icon">&#9888;&#65039;</div>
    <p>{{ $message }}</p>
</div>
</body>
</html>
