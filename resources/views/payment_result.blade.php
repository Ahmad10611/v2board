<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazir-font@v26.0.2/dist/font-face.css" rel="stylesheet" type="text/css" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazir', sans-serif;
            background-color: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            transition: background-color 0.3s;
        }

        /* استایل برای حالت تاریک */
        body.dark-mode {
            background-color: #333;
            color: #f0f0f0;
        }

        .payment-result {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
            padding: 30px;
            text-align: center;
            max-width: 80%;
            transition: background-color 0.3s, color 0.3s;
            animation: fadeIn 1s ease-in-out;
        }

        /* استایل برای حالت تاریک */
        .dark-mode .payment-result {
            background-color: #444;
            color: #f0f0f0;
        }

        .icon {
            font-size: 64px;
            margin-bottom: 20px;
            color: {{ $color }};
            animation: bounce 1s infinite;
        }

        h1 {
            color: {{ $color }};
            margin-bottom: 10px;
            animation: fadeInDown 0.5s ease-in-out;
        }

        /* استایل برای حالت تاریک */
        .dark-mode h1 {
            color: #66BB6A;
        }

        p {
            font-size: 20px;
            color: #333;
            margin-bottom: 5px;
            animation: fadeInUp 0.5s ease-in-out;
        }

        /* استایل برای حالت تاریک */
        .dark-mode p {
            color: #f0f0f0;
        }

        .order-info {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            animation: fadeInUp 0.5s ease-in-out;
        }

        /* استایل برای حالت تاریک */
        .dark-mode .order-info {
            border-top: 1px solid #666;
        }

        .button {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            font-size: 20px;
            color: white;
            background-color: {{ $color }};
            text-decoration: none;
            border-radius: 8px;
            transition: background-color 0.3s;
            animation: fadeInUp 0.5s ease-in-out;
        }

        /* استایل برای حالت تاریک */
        .dark-mode .button {
            background-color: #66BB6A;
        }

        .button:hover {
            background-color: darken({{ $color }}, 10%);
        }

        .toggle-dark-mode {
            position: absolute;
            top: 20px;
            right: 20px;
            cursor: pointer;
        }

        .progress-bar {
            width: 100%;
            background-color: #eee;
            border-radius: 5px;
            overflow: hidden;
            margin-top: 20px;
            animation: fadeInUp 0.5s ease-in-out;
        }

        .progress {
            height: 20px;
            background-color: {{ $color }};
            width: 100%;
            animation: progress-animation 2s ease-out;
        }

        .countdown-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 20px;
            animation: fadeInUp 0.5s ease-in-out;
        }

        .countdown {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: {{ $color }};
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-left: 10px;
            animation: pulse 1s infinite;
        }

        /* استایل برای حالت تاریک */
        .dark-mode .countdown {
            background-color: #66BB6A;
        }

        @keyframes progress-animation {
            from {
                width: 0;
            }
            to {
                width: 100%;
            }
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-30px);
            }
            60% {
                transform: translateY(-15px);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
        }
    </style>
</head>
<body>
    <div class="toggle-dark-mode">
        <i class="fas fa-moon"></i>
    </div>
    <div class="payment-result">
        <div class="icon">{{ $icon }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <div class="order-info">
            {!! $orderInfo !!}
        </div>
        <div class="progress-bar">
            <div class="progress"></div>
        </div>
        <a href="{{ $buttonLink }}" class="button">{{ $buttonText }}</a>
        <div class="countdown-wrapper">
            <div class="countdown" id="countdown">10</div>
            <p>ثانیه دیگر به صورت خودکار به صفحه هدایت می‌شوید</p>
        </div>
    </div>
    <script>
        const toggleDarkMode = document.querySelector('.toggle-dark-mode');
        const icon = toggleDarkMode.querySelector('i');
        
        toggleDarkMode.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            icon.classList.toggle('fa-moon');
            icon.classList.toggle('fa-sun');
        });

        // هدایت خودکار بعد از 10 ثانیه
        let countdown = 10;
        const countdownElement = document.getElementById('countdown');
        const countdownInterval = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            if (countdown === 0) {
                clearInterval(countdownInterval);
                window.location.href = "{{ $buttonLink }}";
            }
        }, 1000);
    </script>
</body>
</html>
