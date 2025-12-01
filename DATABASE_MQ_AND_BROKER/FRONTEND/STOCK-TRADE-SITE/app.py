from __future__ import annotations

import json
import os
from datetime import datetime, timedelta
from typing import Any, Dict, Tuple

import requests
from flask import Flask, jsonify, render_template, request

app = Flask(__name__)

ALPHAVANTAGE_URL = "https://www.alphavantage.co/query"
API_KEY_PATH = os.environ.get("ALPHA_VANTAGE_KEY_FILE", "/var/www/sample/alpha-vanguard-api-key.txt")
DEFAULT_INTERVAL = os.environ.get("ALPHA_VANTAGE_INTERVAL", "1min")
REQUEST_TIMEOUT = int(os.environ.get("ALPHA_VANTAGE_TIMEOUT", "10"))
CACHE_TTL_SECONDS = int(os.environ.get("ALPHA_VANTAGE_CACHE_TTL", "45"))

_session = requests.Session()
_price_cache: Dict[str, Tuple[datetime, Dict[str, Any]]] = {}


def load_api_key(path: str) -> str:
    try:
        with open(path, "r", encoding="utf-8") as handle:
            return handle.read().strip()
    except OSError:
        return ""


API_KEY = load_api_key(API_KEY_PATH)


def get_cached_payload(stock_symbol: str, stock_value_interval: str) -> Dict[str, Any] | None:
    stock_cache_key = f"{stock_symbol}:{stock_value_interval}"
    stock_cached = _price_cache.get(stock_cache_key)
    if not  stock_cached:
        return None

    stock_value_timestamp, stock_values_payload = stock_cached
    if datetime.utcnow() - stock_value_timestamp <= timedelta(seconds=CACHE_TTL_SECONDS):
        return stock_values_payload

    _price_cache.pop(stock_cache_key, None)
    return None


def set_cached_payload(stock_symbol: str, stock_value_interval: str, payload: Dict[str, Any]) -> None:
    stock_cache_key = f"{stock_symbol}:{stock_value_interval}"
    _price_cache[stock_cache_key] = (datetime.utcnow(), payload)


def error_response(message: str, detail: Any | None = None, status: int = 400):
    body: Dict[str, Any] = {"error": message}
    if detail is not None:
        body["detail"] = detail
    return jsonify(body), status


@app.route("/")
def index() -> str:
    return render_template("index.html")


@app.route("/getStockValues", methods=["GET"])
def getStockValues():
    stockSymbol = (request.args.get("symbol") or "").strip().upper()
    stockTimeInterval = (request.args.get("interval") or DEFAULT_INTERVAL).strip()

    if not stockSymbol:
        return error_response("Missing symbol parameter", status=400)

    if stockTimeInterval not in {"1min", "5min", "15min", "30min", "60min"}:
        stockTimeInterval = DEFAULT_INTERVAL

    if not API_KEY:
        return error_response("AlphaVantage API key not configured on server", status=500)

    stock_payload = get_cached_payload(stockSymbol, stockTimeInterval)
    if stock_payload:
        return jsonify(stock_payload)

    stock_params = {
        "function": "TIME_SERIES_INTRADAY",
        "symbol": stockSymbol,
        "interval": stockTimeInterval,
        "apikey": API_KEY,
    }

    try:
        stock_response = _session.get(ALPHAVANTAGE_URL, params=stock_params, timeout=REQUEST_TIMEOUT)
        stock_response.raise_for_status()
    except requests.RequestException as exc:
        return error_response("Upstream request failed", detail=str(exc), status=502)

    try:
        stock_payload =  stock_response.json()
    except json.JSONDecodeError:
        return error_response("Upstream returned non-JSON response", status=502)

    ts_key = next((key for key in stock_payload.keys() if key.startswith("Time Series")), None)
    if not ts_key or "Error Message" in stock_payload or "Note" in stock_payload:
        set_cached_payload(stockSymbol, stockTimeInterval, stock_payload)
        return error_response("Could not retrieve time series", detail=stock_payload, status=502)

    set_cached_payload(stockSymbol, stockTimeInterval, stock_payload)
    return jsonify(stock_payload)


if __name__ == "__main__":
    port = int(os.environ.get("STOCK_APP_PORT", "5000"))
    debug = os.environ.get("FLASK_DEBUG", "1") == "1"
    app.run(host="100.101.60.103", port=port, debug=debug)