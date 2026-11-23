import type { Metadata } from 'next'
import { ThemeProvider } from 'next-themes'
import './globals.css'
export const metadata: Metadata = {title:'SMS-Awarie | Panel',description:'Panel demonstracyjny obsługi awarii'}
export default function Layout({children}:{children:React.ReactNode}) {return <html lang="pl" suppressHydrationWarning><body><ThemeProvider attribute="class" defaultTheme="system" enableSystem>{children}</ThemeProvider></body></html>}
