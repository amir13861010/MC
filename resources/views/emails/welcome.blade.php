<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Welcome to OMEGA</title>
  <style>
    :root {
      --background: oklch(0.9851 0 0);
      --foreground: oklch(0 0 0);
      --primary: oklch(0.2469 0.0749 260.778);
    }

    body {
      background-color: var(--background);
      color: var(--foreground);
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      margin: 0;
      padding: 2rem;
    }

    .container {
      max-width: 600px;
      margin: 0 auto;
      background: #ffffff;
      border: 1px solid #e0e0e0;
      border-radius: 10px;
      padding: 2rem;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
    }

    .logo {
      display: block;
      margin: 0 auto 1.5rem auto;
      width: 100%;
      max-width: 80px;
      aspect-ratio: 16 / 9;
      object-fit: contain;
    }

    h1 {
      text-align: center;
      font-size: 1.8rem;
      margin-bottom: 1.2rem;
      color: var(--primary);
    }

    p {
      font-size: 1rem;
      line-height: 1.6;
      margin: 1rem 0;
    }

    .credentials {
      background-color: #f4f4f4;
      border-left: 4px solid var(--primary);
      padding: 1rem;
      margin: 1.5rem 0;
      font-family: monospace;
      font-size: 0.95rem;
    }

    .footer {
      text-align: center;
      font-size: 0.85rem;
      color: #888;
      margin-top: 2rem;
      border-top: 1px solid #eee;
      padding-top: 1rem;
    }
  </style>
</head>
<body>
  <div class="container">
    <img src="https://omegafocus.com/mc-logo.svg" alt="OMEGA Logo" class="logo">

    <h1>Welcome to OMEGA</h1>
    
    <p>Dear User,</p>

    <p>We are pleased to welcome you to <strong>OMEGA</strong>. Your registration has been successfully completed, and your account is now active.</p>

    <p>Below are your login credentials:</p>

    <div class="credentials">
      User ID: <strong>{{ $userId }}</strong><br>
      Password: <strong>{{ $password }}</strong>
    </div>

    <p>For your security, we recommend changing your password upon your first login.</p>

    <p>If you require any assistance, please do not hesitate to contact our support team.</p>

    <p>We look forward to supporting your success.</p>

    <p>Warm regards,<br>
    The OMEGA Team</p>

    <div class="footer">
      &copy; 2005-2025 OMEGA. All rights reserved.
    </div>
  </div>
</body>
</html>
