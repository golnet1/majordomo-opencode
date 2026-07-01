import sys, os, json, hashlib, urllib.request, urllib.parse, re, html

from mcp.server.fastmcp import FastMCP

mcp = FastMCP("music")

ZAYCEV_STATIC_KEY = os.environ.get("ZAYCEV_KEY", "kmskoNkYHDnl3ol2")
ZAYCEV_ACCESS_TOKEN = None
LASTFM_API_KEY = os.environ.get("LASTFM_KEY", "")
VK_TOKEN = os.environ.get("VK_TOKEN", "")

def fetch_json(url, timeout=15):
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode())

def fetch_json_safe(url, timeout=15):
    try:
        return fetch_json(url, timeout)
    except urllib.error.HTTPError as e:
        return None
    except Exception as e:
        return None

def fetch_text(url, timeout=15):
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read().decode(errors="replace")

def zaycev_auth():
    global ZAYCEV_ACCESS_TOKEN
    if ZAYCEV_ACCESS_TOKEN:
        return ZAYCEV_ACCESS_TOKEN
    data = fetch_json("https://api.zaycev.net/external/hello")
    hello_token = data["token"]
    h = hashlib.md5((hello_token + ZAYCEV_STATIC_KEY).encode()).hexdigest()
    data = fetch_json(f"https://api.zaycev.net/external/auth?code={urllib.parse.quote(hello_token)}&hash={h}")
    ZAYCEV_ACCESS_TOKEN = data["token"]
    return ZAYCEV_ACCESS_TOKEN

def zaycev_search(query: str, limit: int = 5):
    token = zaycev_auth()
    params = urllib.parse.urlencode({
        "access_token": token, "query": query,
        "page": 1, "type": "all", "sort": "", "style": ""
    })
    data = fetch_json_safe(f"https://api.zaycev.net/external/search?{params}")
    if data is None:
        return []
    results = []
    for t in data.get("tracks", []):
        dur = t.get("duration", "")
        dur_sec = 0
        if ":" in str(dur):
            parts = str(dur).split(":")
            dur_sec = int(parts[0]) * 60 + int(parts[1])
        results.append({
            "title": t.get("track", ""),
            "artist": t.get("artistName", ""),
            "album": "",
            "duration": dur_sec,
            "preview": "",
            "link": f"https://zaycev.net/search?q={urllib.parse.quote(t.get('track', '') + ' ' + t.get('artistName', ''))}",
            "source": "Zaycev.net",
            "track_id": t.get("id", 0)
        })
        if len(results) >= limit:
            break
    return results

def lastfm_search(query: str, limit: int = 5):
    if not LASTFM_API_KEY:
        return []
    params = urllib.parse.urlencode({
        "method": "track.search",
        "track": query,
        "api_key": LASTFM_API_KEY,
        "format": "json",
        "limit": limit
    })
    data = fetch_json(f"https://ws.audioscrobbler.com/2.0/?{params}")
    results = []
    for t in data.get("results", {}).get("trackmatches", {}).get("track", []):
        results.append({
            "title": t.get("name", ""),
            "artist": t.get("artist", ""),
            "album": "",
            "duration": 0,
            "preview": "",
            "link": t.get("url", ""),
            "source": "Last.fm"
        })
    return results

def vk_search(query: str, limit: int = 5):
    if not VK_TOKEN:
        return []
    params = urllib.parse.urlencode({
        "q": query,
        "count": limit,
        "access_token": VK_TOKEN,
        "v": "5.199"
    }, doseq=True)
    data = fetch_json(f"https://api.vk.com/method/audio.search?{params}")
    results = []
    for t in data.get("response", {}).get("items", []):
        dur = t.get("duration", 0)
        artist = t.get("artist", "")
        title = t.get("title", "")
        results.append({
            "title": title,
            "artist": artist,
            "album": "",
            "duration": dur,
            "preview": t.get("url", ""),
            "link": f"https://vk.com/audio{t.get('owner_id', '')}_{t.get('id', '')}",
            "source": "VK"
        })
    return results

@mcp.tool()
def search_music(query: str, artist: str = "") -> str:
    if artist:
        query = f"{artist} {query}"
    sources = [
        ("Zaycev.net", zaycev_search),
        ("Last.fm", lastfm_search),
    ]
    if VK_TOKEN:
        sources.append(("VK", vk_search))
    all_results = []
    errors = []
    used_sources = []
    for name, fn in sources:
        try:
            results = fn(query)
            if results:
                used_sources.append(name)
                all_results.extend(results)
                if len(all_results) >= 15:
                    break
        except Exception as e:
            errors.append(f"{name}: {e}")
    if not all_results:
        msg = "Ничего не найдено."
        if errors:
            msg += f" Ошибки: {'; '.join(errors)}"
        return msg
    lines = [f"Найдено в: {', '.join(used_sources)}"]
    for i, r in enumerate(all_results[:15], 1):
        dur = ""
        if r.get("duration"):
            m, s = divmod(r["duration"], 60)
            dur = f"{m}:{s:02d}"
        parts = [f"{i}. {r['title']} — {r['artist']}"]
        if r.get("album"):
            parts.append(f"[{r['album']}]")
        if dur:
            parts.append(dur)
        parts.append(f"({r['source']})")
        if r.get("link"):
            parts.append(r["link"])
        lines.append(" | ".join(parts))
    return "\n".join(lines)

if __name__ == "__main__":
    mcp.run(transport="stdio")
