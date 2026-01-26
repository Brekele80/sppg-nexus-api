// signup.js
import { createClient } from "@supabase/supabase-js";

const url = process.env.SUPABASE_URL;
const anon = process.env.SUPABASE_ANON_KEY;
const email = process.env.EMAIL;
const password = process.env.PASSWORD;

if (!url || !anon || !email || !password) {
  console.error("Missing env: SUPABASE_URL, SUPABASE_ANON_KEY, EMAIL, PASSWORD");
  process.exit(1);
}

const supabase = createClient(url, anon);

const { data, error } = await supabase.auth.signUp({ email, password });
if (error) {
  console.error(error);
  process.exit(1);
}

console.log(JSON.stringify({ user: data.user }, null, 2));
