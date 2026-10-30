<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name') }} mobile API · Swagger</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css">
    <style>
        body { margin: 0; background: #fafafa; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
        .lms-bar { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; justify-content: space-between; padding: 12px 20px; background: linear-gradient(90deg, #0f172a, #2563eb); color: #fff; }
        .lms-bar b { font-size: 16px; }
        .lms-bar span { font-size: 13px; opacity: .9; }
        .lms-bar code { background: rgba(255,255,255,.15); padding: 2px 6px; border-radius: 6px; }
        .swagger-ui .topbar { display: none; }
    </style>
</head>
<body>
    <div class="lms-bar">
        <b>{{ config('app.name') }} · mobile API v1</b>
        <span>1. Run <code>POST /api/v1/auth/login</code> &nbsp; 2. Copy the <code>token</code> &nbsp; 3. Press <b>Authorize</b> and paste it &nbsp; 4. Use your library <code>slug</code> in library calls</span>
    </div>
    <div id="swagger-ui"></div>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
    <script>
        window.ui = SwaggerUIBundle({
            url: @json($specUrl),
            dom_id: '#swagger-ui',
            deepLinking: true,
            persistAuthorization: true,
            docExpansion: 'none',
            filter: true,
            tryItOutEnabled: true,
            displayRequestDuration: true,
            defaultModelsExpandDepth: 0,
            requestInterceptor: (req) => { req.headers['Accept'] = 'application/json'; return req; },
        });
    </script>
</body>
</html>
