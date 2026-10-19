"""End-to-end test of the "Demo: all question types" study in Telegram.

Plays a participant through a real Telegram user account (Telethon), answering every item with
the matching kind of reply: buttons, typed text, toggles, a voice note, a photo, a document and a
location. Mini App items (slider, keystroke writer, cognitive task) are answered through the live
handoff API, exactly as the handoff page does. Exits non-zero unless the bot confirms saving.

Needs a Telethon session for a user account (api_id/api_hash from my.telegram.org):
  TELEGRAM_API_ID, TELEGRAM_API_HASH, TELEGRAM_SESSION (session file path) in the environment
  or a .env file given with --env.

Usage:
  uv run --with telethon --with python-dotenv tools/e2e/telegram_demo.py <bot_username> <start_payload> <esmira_base_url> [--env path/.env]
  e.g. ... my_test_bot s12345678_demoall https://ema.example.org/esmira/
"""
import asyncio, os, sys
from pathlib import Path
from dotenv import load_dotenv
from telethon import TelegramClient
from telethon.tl.types import KeyboardButtonWebView, KeyboardButtonRequestGeoLocation, InputMediaGeoPoint, InputGeoPoint

if "--env" in sys.argv:
    load_dotenv(sys.argv[sys.argv.index("--env") + 1])
D = Path(__file__).parent / "media"
BOT = sys.argv[1]
START = sys.argv[2]
API = sys.argv[3].rstrip("/") + "/api/channel_handoff.php"

async def last(client, n=1):
    msgs = await client.get_messages(BOT, limit=n)
    return [m for m in msgs if not m.out]

def flat(m):
    return [b for row in (m.buttons or []) for b in row]

async def wait_change(client, prev_id, prev_text, timeout=20):
    for _ in range(timeout * 2):
        await asyncio.sleep(0.5)
        msgs = await client.get_messages(BOT, limit=1)
        m = msgs[0]
        if not m.out and (m.id != prev_id or (m.text or "") != prev_text):
            await asyncio.sleep(1.2)  # let the rest of a burst arrive
            return (await client.get_messages(BOT, limit=1))[0]
    return (await client.get_messages(BOT, limit=1))[0]

def handoff(url):
    """Do what the Mini App page does: read the item, post a result (live handoff API)."""
    import json, urllib.parse, urllib.request
    token = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)["t"][0]
    api = API
    item = json.load(urllib.request.urlopen(f"{api}?t={urllib.parse.quote(token)}"))["dataset"]
    kind = item["kind"]
    if kind == "va_scale":
        body = {"value": "63"}
    elif kind == "keystroke":
        body = {"keystrokeText": "Typed in the Mini App", "captureMode": "soft",
                "keystrokeLog": "class,hold,release,press\nletter,80,0,0\nletter,75,120,200\n"}
    else:
        body = {"value": json.dumps({"session": "e2e", "n_trials": 2}),
                "trials": [{"cogAssessment": "pvt-ba", "cogTrialIndex": 0, "cogRt": 312},
                           {"cogAssessment": "pvt-ba", "cogTrialIndex": 1, "cogRt": 298}]}
    req = urllib.request.Request(api, data=json.dumps({"t": token, **body}).encode(), headers={"Content-Type": "application/json"})
    ok = json.load(urllib.request.urlopen(req)).get("success")
    return f"handoff {kind} ({item['input']['name']}) posted success={ok}"

async def act(client, m):
    text = (m.text or "")
    low = text.lower()
    btns = flat(m)
    labels = [b.text for b in btns]
    def click(pred):
        for b in btns:
            if pred(b.text):
                return b
        return None
    # reply keyboard (location)
    if m.reply_markup is not None and any(isinstance(b.button, KeyboardButtonRequestGeoLocation) for b in btns):
        await client.send_file(BOT, InputMediaGeoPoint(InputGeoPoint(51.2425, -0.5897)))
        return "sent location"
    web = [b for b in btns if isinstance(b.button, KeyboardButtonWebView)]
    if web:
        return handoff(web[0].button.url)
    if "I consent" in labels:
        await click(lambda t: t == "I consent").click(); return "consent"
    if "what time is it" in low:
        await btns[0].click(); return f"time zone button {btns[0].text}"
    if "which questionnaire" in low:
        await click(lambda t: "All question types" in t).click(); return "open All question types"
    if any(t.startswith("▫️") or t.startswith("✅") for t in labels):
        if sum(t.startswith("✅") for t in labels) < 2:
            b = click(lambda t: t.startswith("▫️")); await b.click(); return f"toggle {b.text}"
        await click(lambda t: t == "Done").click(); return "Done"
    if "Somewhere else" in labels:
        await click(lambda t: t == "Somewhere else").click(); return "choose Somewhere else"
    if "please describe your answer" in low:
        await client.send_message(BOT, "At the gym"); return "other text"
    if "Today" in labels:
        await click(lambda t: t == "Today").click(); return "date Today"
    if "Continue" in labels:
        await click(lambda t: t == "Continue").click(); return "Continue"
    if "voice message" in low and "keystroke" not in low:
        await client.send_file(BOT, str(D / "voice.ogg"), voice_note=True); return "voice note"
    if "file upload" in low:
        await client.send_file(BOT, str(D / "photo.png"), force_document=True); return "document"
    if "send a photo" in low:
        await client.send_file(BOT, str(D / "photo.png")); return "photo"
    if "are you alone" in low:
        await click(lambda t: t == "No").click(); return "binary No"
    typed = await typed_answer(client, low)
    if typed:
        return typed
    if btns and any(t.isdigit() for t in labels) and all(t.isdigit() for t in labels if t != "Skip"):
        b = click(lambda t: t == "4") or btns[0]; await b.click(); return f"scale {b.text}"
    if btns:
        await btns[0].click(); return f"first button {btns[0].text}"
    return None

async def typed_answer(client, low):
    if "type a time" in low:
        await client.send_message(BOT, "23:30"); return "time 23:30"
    if "type a duration" in low:
        ans = "20m" if "fall asleep" in low else "7h 30m"
        await client.send_message(BOT, ans); return f"duration {ans}"
    if "whole number" in low:
        await client.send_message(BOT, "2"); return "int 2"
    if "type a number" in low:
        await client.send_message(BOT, "36,6"); return "decimal 36,6"
    if "type your answer" in low:
        await client.send_message(BOT, "Testing from Telegram"); return "free text"
    return None

async def main():
    client = TelegramClient(os.environ["TELEGRAM_SESSION"], int(os.environ["TELEGRAM_API_ID"]), os.environ["TELEGRAM_API_HASH"])
    await client.connect()
    await client.send_message(BOT, f"/start {START}")
    m = await wait_change(client, 0, "")
    for step in range(80):
        txt = (m.text or "").replace("\n", " ")
        if "answers have been saved" in txt.lower():
            print(f"[{step}] DONE: {txt[:90]}")
            await client.disconnect()
            return 0
        if "you have joined" in txt.lower() or "already taking part" in txt.lower():
            await client.send_message(BOT, "/checkin"); action = "/checkin"
        else:
            action = await act(client, m)
        print(f"[{step}] bot: {txt[:80]!r} → {action}")
        if action is None:
            print("no action for this message; stopping"); break
        m = await wait_change(client, m.id, m.text or "")
    await client.disconnect()
    return 1

sys.exit(asyncio.run(main()))
