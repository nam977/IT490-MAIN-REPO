import requests

# Define the login URL and headers
login_url = "http://localhost/login.html"
headers = {
    "Content-Type": "application/json",
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/94.0.4606.81 Safari/537.36"
}

# Define the login credentials (replace these with your actual credentials)
login_data = {
    "username": "wendys",
    "password": "1234"
}

session = requests.Session()

# Send the login request
response = session.post(login_url, json=login_data, headers=headers)

# Check if login was successful
if response.status_code == 200:
    # Assuming the response is in JSON format, print the response
    print(response.json())
else:
    print(f"Login failed with status code: {response.status_code}")
