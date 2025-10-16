import subprocess
import webbrowser
import time
import os

PORT = 8080
PHP_PATH = r"C:\xampp\php\php.exe"  


# Get folder of this script
script_dir = os.path.dirname(os.path.abspath(__file__))

# Folder containing your PHP files
php_folder = os.path.join(script_dir, "php")

# Start PHP built-in server
server = subprocess.Popen(
    [PHP_PATH, "-S", f"localhost:{PORT}"],
    cwd=php_folder
)

time.sleep(1)


webbrowser.open(f"http://localhost:{PORT}/Login.php")

try:
    
    server.wait()
except KeyboardInterrupt:
    print("Shutting down server...")
    server.terminate()
