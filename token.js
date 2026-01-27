import { createClient } from "@supabase/supabase-js";

const supabaseUrl = process.env.SUPABASE_URL;
const supabaseAnonKey = process.env.SUPABASE_ANON_KEY;
const email = process.env.EMAIL;
const password = process.env.PASSWORD;

if (!supabaseUrl) throw new Error("Missing SUPABASE_URL");
if (!supabaseAnonKey) throw new Error("Missing SUPABASE_ANON_KEY");
if (!email) throw new Error("Missing EMAIL");
if (!password) throw new Error("Missing PASSWORD");

const supabase = createClient(supabaseUrl, supabaseAnonKey, {
  auth: { persistSession: false, autoRefreshToken: false },
});

const { data, error } = await supabase.auth.signInWithPassword({ email, password });
if (error) {
  console.error(error.message);
  process.exit(1);
}

process.stdout.write(data.session.access_token);
