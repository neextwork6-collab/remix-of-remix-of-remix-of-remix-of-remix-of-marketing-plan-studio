import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useState } from "react";
import { adminApi, adminToken, ADMIN_DEMO_TOKEN } from "@/lib/admin-api";

export const Route = createFileRoute("/admin_/login")({
  head: () => ({
    meta: [
      { title: "Admin Login — Life OS" },
      { name: "description", content: "Sign in to the Life OS admin panel." },
      { name: "robots", content: "noindex, nofollow" },
      { property: "og:title", content: "Admin Login — Life OS" },
      { property: "og:description", content: "Sign in to the Life OS admin panel." },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary" },
    ],
  }),
  ssr: false,
  component: AdminLogin,
});

const CHARS = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
const makeCode = () => Array.from({ length: 5 }, () => CHARS[Math.floor(Math.random() * CHARS.length)]).join("");

function AdminLogin() {
  const navigate = useNavigate();
  const [code, setCode] = useState(makeCode);
  const [show, setShow] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    setErr(null);
    if (String(fd.get("captcha") ?? "").trim().toUpperCase() !== code) {
      setErr("Security code does not match. Try again.");
      setCode(makeCode());
      return;
    }
    setBusy(true);
    try {
      adminToken.clear();
      const r = await adminApi<{ token: string }>("/login", { body: { email: fd.get("email"), password: fd.get("password") } });
      adminToken.set(r.token);
      navigate({ to: "/admin" });
    } catch (x) {
      setErr((x as Error).message === "Failed to fetch" ? "Admin server is not reachable right now." : (x as Error).message);
      setCode(makeCode());
    } finally { setBusy(false); }
  };

  const demo = () => { adminToken.set(ADMIN_DEMO_TOKEN); navigate({ to: "/admin" }); };

  return (
    <div className="login-page">
      <div className="login-card">
        <aside className="login-side">
          <p className="login-side-eyebrow">WE ARE THE</p>
          <p className="login-side-brand">Life OS</p>
          <h1 className="login-side-title">Admin Panel</h1>
          <p className="login-side-sub">Site configuration, members and company accounts</p>
        </aside>
        <section className="login-main">
          <h2 className="login-title">Admin Login</h2>
          <p className="login-sub">Enter your admin email, password and the security code.</p>
          <form className="login-form" onSubmit={submit}>
            <input className="login-input" type="email" name="email" placeholder="Admin email *" required autoComplete="username" />
            <div className="login-password">
              <input className="login-input" type={show ? "text" : "password"} name="password" placeholder="Password *" required autoComplete="current-password" />
              <button type="button" className="login-eye" aria-label={show ? "Hide password" : "Show password"} onClick={() => setShow(!show)}>
                {show ? "Hide" : "Show"}
              </button>
            </div>
            <div className="login-row">
              <label className="login-remember">
                Security code: <b style={{ letterSpacing: 3, background: "#eef7f1", padding: "4px 10px", borderRadius: 6, userSelect: "none" }}>{code}</b>
              </label>
              <button type="button" className="login-link" style={{ background: "none", border: 0, cursor: "pointer" }} onClick={() => setCode(makeCode())}>Refresh</button>
            </div>
            <input className="login-input" type="text" name="captcha" placeholder="Type the security code *" required maxLength={5} autoComplete="off" />
            {err && <p role="alert" style={{ color: "#c00", fontWeight: 600 }}>{err}</p>}
            <button className="login-submit" type="submit" disabled={busy}>{busy ? "Signing in…" : "Login"}</button>
            <div className="login-divider"><span>OR</span></div>
            <button type="button" className="login-link" onClick={demo} style={{ background: "none", border: 0, cursor: "pointer" }}>View demo admin panel</button>
          </form>
        </section>
      </div>
    </div>
  );
}
