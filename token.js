import { createClient } from "@supabase/supabase-js";

const supabaseUrl = process.env.SUPABASE_URL;
const supabaseAnonKey = process.env.SUPABASE_ANON_KEY;
const email = process.env.EMAIL;
const password = process.env.PASSWORD;

const supabase = createClient(supabaseUrl, supabaseAnonKey);

const { data, error } = await supabase.auth.signInWithPassword({ email, password });
if (error) {
  console.error(error.message);
  process.exit(1);
}

process.stdout.write(data.session.access_token);
