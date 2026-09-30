<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lead Import</title>
    <style>
        * { box-sizing: border-box; } body { margin: 0; background: #f5f7fb; color: #17233a; font: 16px/1.6 system-ui, sans-serif; }
        main { max-width: 1120px; margin: 48px auto; padding: 0 24px; } a { color: #235bcc; }
        h1 { margin-bottom: 8px; font-size: 32px; } .muted { color: #607087; }
        .card { background: white; border: 1px solid #dae1ec; border-radius: 12px; padding: 24px; margin: 24px 0; }
        button { background: #235bcc; color: white; border: 0; border-radius: 6px; padding: 12px 20px; font: inherit; cursor: pointer; }
        input { font: inherit; max-width: 100%; margin: 12px 0 20px; } label { display: block; font-weight: 600; }
        .scroll { overflow-x: auto; } table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #e4e9f1; white-space: nowrap; }
        th { color: #607087; } .error { color: #ad2434; } dl { display: flex; flex-wrap: wrap; gap: 24px; }
        dl div { min-width: 160px; } dt { color: #607087; } dd { margin: 0; font-weight: 600; }
        nav { margin-top: 20px; } nav svg { width: 20px; } .filename { overflow-wrap: anywhere; }
        [hidden] { display: none !important; } button:disabled { opacity: .6; cursor: wait; }
        .success { color: #167443; } .messages { white-space: pre-line; }
    </style>
    @yield('head')
</head>
<body><main>@yield('content')</main><script src="{{ asset('js/imports.js') }}" defer></script></body>
</html>
