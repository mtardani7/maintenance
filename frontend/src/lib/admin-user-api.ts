import { apiRequest, ApiConfigurationError, ApiError } from './api';
import type { Role } from './types';
import type { User } from './types';

const configuredUsersPath = process.env.NEXT_PUBLIC_ADMIN_USERS_PATH ?? '/admin/users';
const usersPath = configuredUsersPath.startsWith('/api/') ? configuredUsersPath.slice(4) : configuredUsersPath;

export type ManagedUser = {
  id: number | string;
  name: string;
  email: string;
  phone: string | null;
  nik: string | null;
  role: Role;
  is_active: boolean;
};

export type UserPage = { data: ManagedUser[]; current_page: number; last_page: number; total: number };
export type UserInput = Omit<ManagedUser, 'id'> & { password?: string; password_confirmation?: string };
export type UserFilters = { search: string; role: string; status: string; page: number; per_page: number };
export type CreateUserInput = { name: string; email: string; role: Role; password: string; password_confirmation: string };

export function getManagedUsers(filters: UserFilters) {
  const query = new URLSearchParams({ search: filters.search, role: filters.role, status: filters.status, page: String(filters.page), per_page: String(filters.per_page) });
  return apiRequest<UserPage>(`${usersPath}?${query.toString()}`);
}

export function createManagedUser(input: UserInput) {
  return apiRequest<ManagedUser>(usersPath, { method: 'POST', body: JSON.stringify(input) });
}

export function createUser(input: CreateUserInput) {
  return apiRequest<User>(usersPath, { method: 'POST', body: JSON.stringify(input) });
}

export function updateManagedUser(id: ManagedUser['id'], input: UserInput) {
  return apiRequest<ManagedUser>(`${usersPath}/${id}`, { method: 'PUT', body: JSON.stringify(input) });
}

export function adminUserApiMessage(error: unknown) {
  if (error instanceof ApiError) {
    if (error.status === 401) return 'Sesi berakhir. Silakan masuk kembali.';
    if (error.status === 403) return 'Hanya Admin yang dapat mengelola pengguna.';
    if (error.status === 422) return error.message || 'Periksa kembali data pengguna.';
    if (error.status >= 500) return 'Layanan pengguna sedang tidak tersedia. Coba lagi.';
  }
  if (error instanceof ApiConfigurationError) return error.message;
  if (error instanceof TypeError) return 'Layanan pengguna tidak dapat dihubungi.';
  return 'Data pengguna tidak dapat diproses.';
}
