
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>تسجيل الدخول - معهد الأمل للأيتام</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Tahoma, Arial, sans-serif;
            background: #f2f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-box {
            width: 100%;
            max-width: 420px;
            background: white;
            padding: 35px;
            border-radius: 14px;
            box-shadow: 0 8px 30px #00000012;
        }

        h2 {
            text-align: center;
            color: #17365d;
        }

        .subtitle {
            text-align: center;
            color: #777;
            margin-bottom: 30px;
        }

        label {
            display: block;
            margin: 15px 0 7px;
        }

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
        }

        button {
            width: 100%;
            margin-top: 25px;
            padding: 14px;
            border: none;
            border-radius: 8px;
            background: #17365d;
            color: white;
            cursor: pointer;
            font-size: 16px;
        }

        .error {
            color: #b91c1c;
            font-size: 13px;
            margin-top: 7px;
        }
    </style>
</head>

<body>
    <div class="login-box">
        <h2>جمعية معهد الأمل للأيتام</h2>
        <p class="subtitle">نظام الإدارة المؤسسية AIMS</p>

        <form method="POST" action="{{ route('login') }}">
            @csrf

           <label for="employee_number">الرقم الوظيفي</label>

<input
    type="text"
    id="employee_number"
    name="employee_number"
    value="{{ old('employee_number') }}"
    placeholder="أدخل الرقم الوظيفي"
    required
    autofocus
    autocomplete="username"
>
            @error('employee_number')
                <div class="error">{{ $message }}</div>
            @enderror

            <label for="password">كلمة المرور</label>
            <input
                type="password"
                id="password"
                name="password"
                required
                autocomplete="current-password"
            >

            @error('password')
                <div class="error">{{ $message }}</div>
            @enderror

            <button type="submit">تسجيل الدخول</button>
        </form>
    </div>
</body>
</html>
