// st04-load.mjs: ST-04 reliability test. 34 interns log in, then all 34 submit a document at the same moment.
// Needs the 4 servers running (start-servers.bat) and the accounts from st04-setup.php.
// Run in the nfc-backend folder:   node st04-load.mjs
//
// Logins come from one laptop, and the server lets one address log in 20 times a minute (against password
// guessing), so the logins go in two groups a minute apart. On 34 real devices each one has its own address.
// The 34 submissions are then sent all at once, spread over the 4 servers like the web app does.

const HOST = "http://localhost";
const PORTS = [8000, 8001, 8002, 8003];
const N = 34;
const accounts = Array.from({ length: N }, (_, i) => `st04-${String(i + 1).padStart(2, "0")}@demo.test`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const api = (i, path) => `${HOST}:${PORTS[i % PORTS.length]}/api${path}`;

async function login(email, i) {
  const res = await fetch(api(i, "/login"), {
    method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ email, password: "Password123!" }),
  });
  const d = await res.json().catch(() => ({}));
  if (!res.ok || !d.token) throw new Error(`${res.status} ${d.message || "no token"}`);
  return d;
}

// a small PDF made on the spot (one page, the account's name on it)
const pdf = (who) => new Blob([`%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 100]>>endobj\n% ST-04 test file for ${who}\ntrailer<</Root 1 0 R>>\n%%EOF\n`], { type: "application/pdf" });

async function submit(u, i) {
  const fd = new FormData();
  fd.append("user_id", String(u.id));
  fd.append("title", "ST-04 test document");
  fd.append("requirement", "others");
  fd.append("phase", "pre");
  fd.append("file", pdf(u.email), `st04-${i + 1}.pdf`);
  const t = performance.now();
  const res = await fetch(api(i, "/intern/submit-mov"), { method: "POST", headers: { Accept: "application/json", Authorization: `Bearer ${u.token}` }, body: fd });
  const d = await res.json().catch(() => ({}));
  return { ok: res.ok, status: res.status, ms: Math.round(performance.now() - t), msg: d.message || "" };
}

console.log(`ST-04: ${N} interns log in, then submit at the same time\n`);
const users = [];
const failedLogins = [];
for (let g = 0; g < N; g += 17) {
  const group = accounts.slice(g, g + 17);
  console.log(`Logging in ${g + 1}-${g + group.length} ...`);
  const r = await Promise.allSettled(group.map((e, k) => login(e, g + k)));
  r.forEach((x, k) => (x.status === "fulfilled" ? users.push({ ...x.value, email: group[k] }) : failedLogins.push(`${group[k]}: ${x.reason.message}`)));
  if (g + 17 < N) { console.log("Waiting 61 s (login limit per address) ..."); await sleep(61000); }
}
console.log(`Logged in: ${users.length} of ${N}${failedLogins.length ? "\n  " + failedLogins.join("\n  ") : ""}\n`);

console.log(`Sending ${users.length} submissions at the same time ...`);
const t0 = performance.now();
const results = await Promise.all(users.map((u, i) => submit(u, i)));
const total = ((performance.now() - t0) / 1000).toFixed(2);

const ok = results.filter((r) => r.ok);
const bad = results.map((r, i) => ({ ...r, email: users[i].email })).filter((r) => !r.ok);
const ms = ok.map((r) => r.ms).sort((a, b) => a - b);
console.log(`\nSaved:   ${ok.length} of ${users.length}`);
console.log(`Errors:  ${bad.length}`);
bad.forEach((b) => console.log(`  ${b.email}: HTTP ${b.status} ${b.msg}`));
if (ms.length) console.log(`Time per submission: fastest ${(ms[0] / 1000).toFixed(2)} s · average ${(ms.reduce((a, b) => a + b, 0) / ms.length / 1000).toFixed(2)} s · slowest ${(ms[ms.length - 1] / 1000).toFixed(2)} s`);
console.log(`All ${users.length} finished in ${total} s`);
