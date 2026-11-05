export type Role = 'operator' | 'technician' | 'supervisor' | 'qa' | 'admin';

export type User = {
  id: number | string;
  name: string;
  email: string;
  plant_id?: number | null;
  role?: Role;
  avatarUrl?: string;
};

export type AuthStatus = 'authenticated' | 'unauthenticated' | 'unavailable';

export type AuthResult =
  | { status: 'authenticated'; user: User }
  | { status: 'unauthenticated' }
  | { status: 'unavailable'; message: string };

export type ApiErrorPayload = {
  message?: string;
  errors?: Record<string, string[]>;
};
