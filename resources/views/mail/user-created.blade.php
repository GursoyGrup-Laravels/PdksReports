<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('ui.user_created') }}</title>
    <style>
        /* Mobil cihazlarda ve modern istemcilerde şifrenin kolay seçilmesini sağlar */
        .password-box {
            user-select: all !important;
            -webkit-user-select: all !important;
            -moz-user-select: all !important;
            -ms-user-select: all !important;
        }
        @media only screen and (max-width: 600px) {
            .container {
                width: 100% !important;
            }
            .content-padding {
                padding: 20px !important;
            }
        }
    </style>
</head>
<body style="font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333333; margin: 0; padding: 0; background-color: #f4f7f9;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f7f9;">
    <tr>
        <td align="center" style="padding: 20px 0;">
            <table class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); border-collapse: collapse; overflow: hidden;">

                <!-- Header / Başlık -->
                <tr>
                    <td align="center" style="background-color: #007bff; padding: 35px 20px;">
                        <h1 style="margin: 0; color: #ffffff; font-size: 26px; font-weight: bold; letter-spacing: 1px;">
                            {{ config('app.name') }}
                        </h1>
                        <p style="margin: 10px 0 0 0; color: #ffffff; font-size: 18px;">
                            {{ __('ui.user_created') }}
                        </p>
                    </td>
                </tr>

                <!-- Content / İçerik -->
                <tr>
                    <td class="content-padding" style="padding: 40px;">
                        <p style="margin-bottom: 25px; font-size: 18px; color: #2d3436;">
                            {{ __('ui.hello') }} <strong>{{ $user?->name }}</strong>,
                        </p>

                        <p style="margin-bottom: 30px; font-size: 16px; color: #636e72;">
                            {{ __('ui.user_created_message') }}
                        </p>

                        <!-- Kullanıcı Bilgileri Kartı -->
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8f9fa; border-radius: 8px; border: 1px solid #e1e8ed; margin-bottom: 30px;">
                            <tr>
                                <td style="padding: 20px;">
                                    <!-- E-posta Bölümü -->
                                    <div style="margin-bottom: 15px;">
                                        <small style="color: #adb5bd; text-transform: uppercase; font-weight: bold; font-size: 11px; letter-spacing: 1px; display: block; margin-bottom: 4px;">{{ __('ui.email') }}</small>
                                        <span style="font-size: 16px; font-weight: 600; color: #2d3436;">{{ $user->email }}</span>
                                    </div>

                                    <!-- Şifre Bölümü (Kopyalanması en kolay alan) -->
                                    <div>
                                        <small style="color: #adb5bd; text-transform: uppercase; font-weight: bold; font-size: 11px; letter-spacing: 1px; display: block; margin-bottom: 8px;">{{ __('ui.password') }}</small>
                                        <div class="password-box" style="background-color: #ffffff; border: 2px dashed #007bff; padding: 15px; border-radius: 6px; text-align: center;">
                                            <code style="font-family: 'Courier New', Courier, monospace; font-size: 22px; font-weight: bold; color: #dc3545; letter-spacing: 2px; display: block;">
                                                {{ $password }}
                                            </code>
                                        </div>
                                        <p style="text-align: center; margin: 8px 0 0 0; font-size: 12px; color: #95a5a6;">
                                            (Kopyalamak için şifreye çift tıklayın)
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <!-- CTA / Aksiyon Butonu -->
                        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom: 35px;">
                            <tr>
                                <td align="center">
                                    <a href="{{ config('app.url') }}" target="_blank" style="display: inline-block; padding: 15px 35px; font-size: 16px; color: #ffffff; background-color: #28a745; border-radius: 8px; text-decoration: none; font-weight: bold; transition: background-color 0.3s ease;">
                                        {{ __('ui.link_to_panel') }}
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <!-- Uyarı Mesajı -->
                        <div style="background-color: #fffaf0; border-left: 4px solid #f39c12; padding: 15px; border-radius: 4px; margin-bottom: 25px;">
                            <p style="margin: 0; font-size: 14px; color: #d35400; line-height: 1.4;">
                                <strong>💡 {{ __('ui.user_created_warning') }}</strong>
                            </p>
                        </div>

                        <p style="font-size: 16px; color: #2d3436; margin-bottom: 5px;">
                            {{ __('ui.enjoy_your_work') }}
                        </p>
                        <p style="font-size: 16px; font-weight: bold; color: #2d3436;">
                            {{ __('ui.best_regards') }}
                        </p>
                    </td>
                </tr>

                <!-- Footer / Alt Bilgi -->
                <tr>
                    <td align="center" style="background-color: #f1f3f5; padding: 25px 20px; border-top: 1px solid #e9ecef;">
                        <p style="margin: 0; font-size: 12px; color: #95a5a6; line-height: 1.5;">
                            {{ __('ui.footer_message') }}
                        </p>
                        <p style="margin: 8px 0 0 0; font-size: 12px; font-weight: bold; color: #7f8c8d;">
                            &copy; {{ date('Y') }} {{ __('ui.gursoy_group') }} - {{ config('app.name') }}
                        </p>
                        <p style="margin: 4px 0 0 0; font-size: 11px; color: #bdc3c7;">
                            {{ __('ui.all_rights_reserved') }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>