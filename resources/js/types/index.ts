export type User = {
  id: number;
  name: string;
  username: string | null;
  email: string;
  activeRole: string | null;
  roles: string[];
};

export type SharedProps = {
  app: { name: string; timezone: string };
  auth: { user: User | null };
  flash: { success?: string; error?: string };
  navigation: MenuItem[];
  notifications: { unreadCount: number; recent: { id: number; type: string; title: string; message: string; link: string | null; read_at: string | null; created_at: string }[] };
};

export type MenuItem = {
  key: string;
  label: string;
  href: string | null;
  icon: string | null;
  children: MenuItem[];
};

export type Stat = {
  label: string;
  value: string;
  detail: string;
  tone: 'blue' | 'amber' | 'violet' | 'emerald';
};
