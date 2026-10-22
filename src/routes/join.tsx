import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { memberApi, memberToken } from "@/lib/member-api";

type Search = { ref?: string | undefined };

export const Route = createFileRoute("/join")({
  validateSearch: (s: Record<string, unknown>): Search => ({ ref: typeof s["ref"] === "string" ? s["ref"] : undefined }),
  head: () => ({
    meta: [
      { title: "Join Life OS — Member Registration" },
      { name: "description", content: "Register a new Life OS member account through your sponsor's referral link." },
      { property: "og:title", content: "Join Life OS — Member Registration" },
      { property: "og:description", content: "Register a new Life OS member account through your sponsor's referral link." },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary" },
    ],
  }),
  component: Join,
});

function Join() {
  const { ref } = Route.useSearch();
  const navigate = useNavigate();
  const [sponsor, setSponsor] = useState<{ name: string; life_id: string } | null>(null);
  const [refErr, setRefErr] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!ref) { setRefErr("রেফার লিংক ছাড়া রেজিস্টার করা যাবে না। স্পনসরের কাছ থেকে লিংক নিন।"); return; }
    memberApi<{ name: string; life_id: string }>(`/sponsor?code=${encodeURIComponent(ref)}`)
      .then(setSponsor)
      .catch(() => setRefErr("এই রেফার লিংকটি সঠিক নয়।"));
  }, [ref]);

  const submit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    if (fd.get("password") !== fd.get("confirm")) { setErr("দুইটা পাসওয়ার্ড মিলছে না।"); return; }
    setErr(null); setBusy(true);
    try {
      memberToken.clear();
      const r = await memberApi<{ token: string; life_id: string }>("/register", {
        body: {
          name: fd.get("name"), phone: fd.get("phone"), email: fd.get("email") || null,
          password: fd.get("password"), referral_code: ref, accepted_terms: fd.get("terms") ? 1 : 0,
        },
      });
      memberToken.set(r.token);
      alert(`রেজিস্ট্রেশন সফল! আপনার Life ID: ${r.life_id}`);
      navigate({ to: "/member" });
    } catch (x) {
      setErr((x as Error).message === "Failed to fetch" ? "সার্ভারে সংযোগ পাওয়া যাচ্ছে না।" : (x as Error).message);
    } finally { setBusy(false); }
  };

  return (
    <div className="login-page">
      <div className="login-card">
        <aside className="login-side">
          <p className="login-side-eyebrow">JOIN</p>
          <p className="login-side-brand">Life OS</p>
          <h1 className="login-side-title">নতুন সদস্য</h1>
          <p className="login-side-sub">
            {sponsor ? <>স্পনসর: <strong>{sponsor.name}</strong> ({sponsor.life_id})<br />প্লেসমেন্ট নিজে থেকে হবে।</> : "স্পনসর যাচাই হচ্ছে…"}
          </p>
        </aside>
        <section className="login-main">
          <h2 className="login-title">রেজিস্ট্রেশন ফর্ম</h2>
          {refErr ? <p role="alert" style={{ color: "#c00", fontWeight: 600 }}>{refErr}</p> : (
            <form className="login-form" onSubmit={submit}>
              <input className="login-input" name="name" placeholder="পূর্ণ নাম *" required maxLength={100} />
              <input className="login-input" name="phone" placeholder="মোবাইল নম্বর (01XXXXXXXXX) *" required pattern="01[0-9]{9}" />
              <input className="login-input" name="email" type="email" placeholder="ইমেইল (ঐচ্ছিক)" />
              <input className="login-input" name="password" type="password" placeholder="পাসওয়ার্ড (কমপক্ষে ৪ অক্ষর) *" required minLength={4} maxLength={72} autoComplete="new-password" />
              <input className="login-input" name="confirm" type="password" placeholder="আবার পাসওয়ার্ড *" required minLength={4} autoComplete="new-password" />
              <label className="login-remember"><input type="checkbox" name="terms" required /> আমি শর্তাবলী মেনে নিচ্ছি</label>
              {err && <p role="alert" style={{ color: "#c00", fontWeight: 600 }}>{err}</p>}
              <button className="login-submit" type="submit" disabled={busy || !sponsor}>{busy ? "রেজিস্টার হচ্ছে…" : "রেজিস্টার করুন"}</button>
            </form>
          )}
        </section>
      </div>
    </div>
  );
}
