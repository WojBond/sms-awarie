import Module from '@/components/module'
export default async function Page({params}:{params:Promise<{section:string}>}){return <Module section={(await params).section}/>}
