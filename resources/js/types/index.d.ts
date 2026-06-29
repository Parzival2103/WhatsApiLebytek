export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

export interface AppConfig {
    layoutMode: 'top' | 'side';
    themeColors: Record<string, string>;
    appName: string;
    pwaThemeColor: string;
    pwaBackgroundColor: string;
    logoArchivoId: number | null;
    faviconArchivoId: number | null;
    pwaIconArchivoId: number | null;
}

export interface AdminMenuNode {
    id: number;
    label: string;
    routeName: string | null;
    icon: string | null;
    children: AdminMenuNode[];
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    appConfig?: AppConfig;
    adminMenu?: AdminMenuNode[];
};
