import { readStoredValue } from '@/lib/safe-storage';

export const isAdmin = (): boolean => readStoredValue('admin') === 'true';
