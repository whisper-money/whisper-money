export const isAdmin = (): boolean => {
    if (typeof window !== 'undefined') {
        const isAdminFlag = localStorage.getItem('admin');
        return isAdminFlag === 'true';
    }

    return false;
};
