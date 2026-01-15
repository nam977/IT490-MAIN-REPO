from flask import Flask, render_template, request, redirect, url_for, jsonify
import requests
import os

app = Flask(__name__)

api_file = open("/var/www/sample/alpha-vanguard-api-key.txt", "r")
API_KEY = api_file.read().strip()
api_file.close()

@app.route('/')
def index():
    return render_template('index.html')

@app.route('/stock', methods=['GET'])
def stock():
    """Proxy endpoint for AlphaVantage TIME_SERIES_INTRADAY.

    Returns the raw JSON from AlphaVantage (or a JSON error object) so the
    client can render charts using the time series data.
    """
    symbol = request.args.get('symbol', '').strip()
    if not symbol:
        return jsonify({'error': 'Missing symbol parameter'}), 400

    url = (
        f'https://www.alphavantage.co/query'
        f'?function=TIME_SERIES_INTRADAY&symbol={symbol}&interval=1min&apikey={API_KEY}'
    )
    try:
        # Use a short timeout to avoid hanging the Flask worker
        response = requests.get(url, timeout=10)
    except requests.exceptions.RequestException as e:
        return jsonify({'error': 'Upstream request failed', 'detail': str(e)}), 502

    # If AlphaVantage returns non-JSON or an error, forward a sensible JSON error
    try:
        data = response.json()
    except ValueError:
        # Not JSON
        return jsonify({'error': 'Upstream returned non-JSON response'}), 502

    # AlphaVantage can include messages like 'Note' or 'Error Message' in the payload
    if 'Error Message' in data or 'Note' in data or not any(k.startswith('Time Series') for k in data.keys()):
        # Forward the raw payload so the client can display diagnostics
        return jsonify({'error': 'Could not retrieve time series', 'upstream': data}), 502

    # Return the full time series payload to the client
    return jsonify(data)
    
if __name__ == '__main__':
    app.run(debug=True)