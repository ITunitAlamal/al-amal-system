' AIMS: runs "php artisan schedule:run" with a hidden window (used by the Interactive scheduler task).
Set shell = CreateObject("WScript.Shell")
shell.CurrentDirectory = "C:\Projects\al-amal-system"
shell.Run """C:\Users\IT-SHADI\.config\herd\bin\php84\php.exe"" artisan schedule:run", 0, True
