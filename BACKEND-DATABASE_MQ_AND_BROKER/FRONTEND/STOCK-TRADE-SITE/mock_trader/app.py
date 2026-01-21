from __future__ import annotations

import os
from datetime import datetime
from typing import Dict, List, Tuple

import requests
from flask import Flask, flash, jsonify, redirect, render_template, request, url_for
from flask_login import current_user, login_required, LoginManager

import mysql.connector
from mysql.connector import Error

app = Flask(__name__)
app.config["SECRET_KEY"] = os.environ.get("MOCK_TRADER_SECRET", "dev-secret")

API_KEY_PATH = os.environ.get("ALPHA_VANTAGE_KEY_FILE", "/var/www/sample/FRONTEND/alpha-vantage-api-key.txt")
try:
    with open(API_KEY_PATH, "r", encoding="utf-8") as fh:
        API_KEY = fh.read().strip()
except OSError:
    API_KEY = ""

STARTING_CASH = float(os.environ.get("MOCK_TRADER_STARTING_CASH", "100000"))
_cash_balance: float = STARTING_CASH
_portfolio: Dict[str, Dict[str, float]] = {}
_trade_history: List[Dict[str, str]] = []


def fetch_latest_price(symbol: str) -> Tuple[float | None, str | None]:
    """Return latest price for symbol or an error message."""
    if not API_KEY:
        return None, "AlphaVantage API key missing"

    params = {
        "function": "GLOBAL_QUOTE",
        "symbol": symbol,
        "apikey": API_KEY,
    }
    try:
        response = requests.get("https://www.alphavantage.co/query", params=params, timeout=6)
        response.raise_for_status()
    except requests.RequestException as exc:
        return None, f"Price lookup failed: {exc}"

    try:
        payload = response.json()
    except ValueError:
        return None, "Price lookup returned non-JSON payload"

    quote = payload.get("Global Quote") or {}
    price_text = quote.get("05. price")
    if not price_text:
        note = quote.get("Note") or payload.get("Note") or payload.get("Error Message")
        return None, note or "No price data available"

    try:
        return float(price_text), None
    except ValueError:
        return None, "Could not parse price from quote"


def build_holdings_snapshot() -> List[Dict[str, float]]:
    snapshot = []
    for symbol, data in sorted(_portfolio.items()):
        shares = data.get("shares", 0.0)
        last_price = data.get("last_price", 0.0)
        snapshot.append(
            {
                "symbol": symbol,
                "shares": shares,
                "avg_price": data.get("avg_price", 0.0),
                "last_price": last_price,
                "market_value": shares * last_price,
            }
        )
    return snapshot


def record_trade(action: str, symbol: str, quantity: int, price: float) -> None:
    timestamp = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
    _trade_history.insert(
        0,
        {
            "timestamp": f"{timestamp} UTC",
            "action": action,
            "symbol": symbol,
            "quantity": quantity,
            "price": price,
        },
    )
    del _trade_history[25:]


@app.route("/")
def dashboard():
    holdings = build_holdings_snapshot()
    holdings_value = sum(item["market_value"] for item in holdings)
    total_equity = _cash_balance + holdings_value
    return render_template(
        "dashboard.html",
        starting_cash=STARTING_CASH,
        cash_balance=_cash_balance,
        holdings=holdings,
        holdings_value=holdings_value,
        total_equity=total_equity,
        trades=_trade_history,
    )


@app.route("/trade", methods=["POST"])
def trade():
    action_raw = (request.form.get("stockAction") or request.form.get("action") or "").strip().lower()
    if action_raw in {"stockbuy", "buy"}:
        action = "buy"
    elif action_raw in {"stocksell", "sell"}:
        action = "sell"
    else:
        action = ""

    symbol = (request.form.get("tickerSymbol") or request.form.get("symbol") or "").strip().upper()
    quantity_text = request.form.get("shareQuantity") or request.form.get("quantity") or "0"

    if action not in {"buy", "sell"}:
        flash("Select buy or sell before submitting a trade.", "error")
        return redirect(url_for("dashboard"))

    if not symbol:
        flash("Symbol is required for a trade.", "error")
        return redirect(url_for("dashboard"))

    try:
        quantity = int(quantity_text)
    except ValueError:
        flash("Quantity must be a whole number.", "error")
        return redirect(url_for("dashboard"))

    if quantity <= 0:
        flash("Quantity must be greater than zero.", "error")
        return redirect(url_for("dashboard"))

    price, price_error = fetch_latest_price(symbol)
    if price is None or price_error:
        flash(price_error or "Unable to fetch price.", "error")
        return redirect(url_for("dashboard"))

    global _cash_balance

    if action == "buy":
        cost = price * quantity
        if cost > _cash_balance:
            flash("Insufficient cash to complete purchase.", "error")
            return redirect(url_for("dashboard"))

        position = _portfolio.setdefault(symbol, {"shares": 0.0, "avg_price": 0.0, "last_price": price})
        total_shares = position["shares"] + quantity
        if total_shares <= 0:
            total_shares = quantity
        position["avg_price"] = ((position["shares"] * position["avg_price"]) + cost) / total_shares
        position["shares"] = total_shares
        position["last_price"] = price
        _cash_balance -= cost
        record_trade("BUY", symbol, quantity, price)
        flash(f"Purchased {quantity} share(s) of {symbol} at ${price:.2f}.", "success")
    else:
        position = _portfolio.get(symbol)
        if not position or position.get("shares", 0.0) < quantity:
            flash("Not enough shares to sell.", "error")
            return redirect(url_for("dashboard"))

        revenue = price * quantity
        position["shares"] -= quantity
        position["last_price"] = price
        _cash_balance += revenue
        if position["shares"] <= 0:
            _portfolio.pop(symbol, None)
        record_trade("SELL", symbol, quantity, price)
        flash(f"Sold {quantity} share(s) of {symbol} at ${price:.2f}.", "success")

    return redirect(url_for("dashboard"))


@app.route("/api/portfolio")
def portfolio_api():
    holdings = build_holdings_snapshot()
    holdings_value = sum(item["market_value"] for item in holdings)
    total_equity = _cash_balance + holdings_value
    return jsonify(
        {
            "status": "success",  
            "starting_cash": STARTING_CASH,
            "cash_balance": _cash_balance,
            "holdings_value": holdings_value,
            "total_equity": total_equity,
            "holdings": holdings,
            "trades": _trade_history,
        }), 200

def get_db():
    return mysql.connector.connect(
        host='localhost',       
        user='testuser',
        password='rv9991$#',  
        database='testdb'   
    )
        
@app.route("/api/trade", methods=["POST"])
def trade_api():
    payload = request.json or {}

    action_raw_data = (payload.get("stockAction") or payload.get("action") or "").strip().lower()

    if action_raw_data in {"stockbuy", "buy"}:
        action = "buy"
    elif action_raw_data in {"stocksell", "sell"}:
        action = "sell"
    else:
        return jsonify({"status": "error", "message": "Invalid action. Use 'buy' or 'sell'."}), 400

    symbol = (payload.get("symbol") or "").strip().upper()

    try:
        quantity = int(payload.get("quantity") or 0)
    except (ValueError, TypeError):
        quantity = 0

    if not symbol:
        return jsonify({"status": "error", "message": "Symbol is required for a trade."}), 400
    if quantity <= 0:    
        return jsonify({"status": "error", "message": "Quantity must be a positive whole number."}), 400

    price, price_error = fetch_latest_price(symbol)

    if price is None:
        return jsonify({"status": "error", "message": price_error or "Unable to fetch price."}), 502

    global _cash_balance

    if action == "buy":
        cost = price * quantity
        if cost > _cash_balance:
            return jsonify({"status": "error", "message": "Insufficient cash to complete purchase."}), 400
        position    = _portfolio.setdefault(symbol, {"shares": 0.0, "avg_price": 0.0, "last_price": price})
        total_shares = position["shares"] + quantity 
        position["avg_price"] = ((position["shares"] * position["avg_price"]) + cost) / total_shares
        position["shares"] = total_shares
        position["last_price"] = price
        _cash_balance -= cost
        record_trade("BUY", symbol, quantity, price)
    else:
        position = _portfolio.get(symbol)
        if not position or position.get("shares", 0.0) < quantity:
            return jsonify({"status": "error", "message": "Not enough shares to sell."}), 400

        revenue = price * quantity
        position["shares"] -= quantity
        position["last_price"] = price
        _cash_balance += revenue
        if position["shares"] <= 0:
            _portfolio.pop(symbol, None)
        record_trade("SELL", symbol, quantity, price)

    holdings = build_holdings_snapshot()
    holdings_value = sum(item["market_value"] for item in holdings)
    total_equity = _cash_balance + holdings_value
 
    myDB = get_db()
    cursor = myDB.cursor()

    username = "steve"


    sql_statement = """INSERT INTO trade_history (username, action, symbol, quantity, price) VALUES (%s, %s, %s, %s, %s)"""
    values = (username, action, symbol, quantity, price)
    cursor.execute(sql_statement, values)

    sql_statement2 = """INSERT INTO stock_prices (symbol, price) VALUES (%s, %s)"""
    values2 = (symbol, price)
    
    cursor.execute(sql_statement, values)
    cursor.execute(sql_statement2, values2)


    myDB.commit()
    cursor.close()
    myDB.close()

    return jsonify({
        'status': 'success',
        'symbol': symbol,
        'price' : price,
        'cash_balance': _cash_balance,
        "holdings_value" : holdings_value,
        "total_equity" : total_equity,
        "holdings" : holdings,
        "trades" : _trade_history,
    }), 200

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5001, debug=True)
