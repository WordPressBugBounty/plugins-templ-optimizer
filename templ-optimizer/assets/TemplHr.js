import{d as c,o,l as _,w as l,b as s,t as r,n as p,f as d,T as m,_ as a}from"./index.js";const x=(n,e)=>(n=n.filter(t=>{let i=!0;return e.disabled_features.includes((t==null?void 0:t.setting)||(t==null?void 0:t.tool))&&(i=!1),i}),n),f=c({__name:"Notification",props:{notification:{}},setup(n){const e=n;return(t,i)=>(o(),_(m,null,{default:l(()=>[e.notification?(o(),s("p",{key:0,class:p(["templ-optimizer-notification","templ-optimizer-notification-"+e.notification.type])},r(t.$t(e.notification.message,{var:e.notification.var})),3)):d("",!0)]),_:1}))}});const b=a(f,[["__scopeId","data-v-9ebaca6d"]]);const u={};function v(n,e){return o(),s("hr")}const g=a(u,[["render",v],["__scopeId","data-v-ee78ce30"]]);export{b as N,g as T,x as f};
