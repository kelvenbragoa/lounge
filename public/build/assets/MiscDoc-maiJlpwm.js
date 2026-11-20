import{s as K}from"./index-C7eR6KOj.js";import{s as V}from"./index-CF5BPi2P.js";import{s as W}from"./index-Cw7A9SEO.js";import{B,a6 as O,J as y,I as w,f as u,c as h,a as s,O as c,a0 as L,a2 as M,Z as z,E as j,n as D,k as x,e as v,q as T,a1 as U,j as C,$ as N,t as G,b as a,r as J,o as Z,bj as Q,F as _}from"./app-CIe1JYpL.js";import{U as A}from"./index-ByRLfN0L.js";import{s as ee}from"./index-Bw0_eAvk.js";import{s as F}from"./index-qrCNRDLS.js";import{s as S}from"./index-C6Yo8Qa6.js";import{s as te}from"./index-CV-jDd5A.js";var ne=function(e){var n=e.dt;return`
.p-scrollpanel-content-container {
    overflow: hidden;
    width: 100%;
    height: 100%;
    position: relative;
    z-index: 1;
    float: left;
}

.p-scrollpanel-content {
    height: calc(100% + calc(2 * `.concat(n("scrollpanel.bar.size"),`));
    width: calc(100% + calc(2 * `).concat(n("scrollpanel.bar.size"),`));
    padding-inline: 0 calc(2 * `).concat(n("scrollpanel.bar.size"),`);
    padding-block: 0 calc(2 * `).concat(n("scrollpanel.bar.size"),`);
    position: relative;
    overflow: auto;
    box-sizing: border-box;
    scrollbar-width: none;
}

.p-scrollpanel-content::-webkit-scrollbar {
    display: none;
}

.p-scrollpanel-bar {
    position: relative;
    border-radius: `).concat(n("scrollpanel.bar.border.radius"),`;
    z-index: 2;
    cursor: pointer;
    opacity: 0;
    outline-color: transparent;
    background: `).concat(n("scrollpanel.bar.background"),`;
    border: 0 none;
    transition: outline-color `).concat(n("scrollpanel.transition.duration"),", opacity ").concat(n("scrollpanel.transition.duration"),`;
}

.p-scrollpanel-bar:focus-visible {
    box-shadow: `).concat(n("scrollpanel.bar.focus.ring.shadow"),`;
    outline: `).concat(n("scrollpanel.barfocus.ring.width")," ").concat(n("scrollpanel.bar.focus.ring.style")," ").concat(n("scrollpanel.bar.focus.ring.color"),`;
    outline-offset: `).concat(n("scrollpanel.barfocus.ring.offset"),`;
}

.p-scrollpanel-bar-y {
    width: `).concat(n("scrollpanel.bar.size"),`;
    inset-block-start: 0;
}

.p-scrollpanel-bar-x {
    height: `).concat(n("scrollpanel.bar.size"),`;
    inset-block-end: 0;
}

.p-scrollpanel-hidden {
    visibility: hidden;
}

.p-scrollpanel:hover .p-scrollpanel-bar,
.p-scrollpanel:active .p-scrollpanel-bar {
    opacity: 1;
}

.p-scrollpanel-grabbed {
    user-select: none;
}
`)},ae={root:"p-scrollpanel p-component",contentContainer:"p-scrollpanel-content-container",content:"p-scrollpanel-content",barX:"p-scrollpanel-bar p-scrollpanel-bar-x",barY:"p-scrollpanel-bar p-scrollpanel-bar-y"},re=B.extend({name:"scrollpanel",theme:ne,classes:ae}),se={name:"BaseScrollPanel",extends:M,props:{step:{type:Number,default:5}},style:re,provide:function(){return{$pcScrollPanel:this,$parentInstance:this}}},P={name:"ScrollPanel",extends:se,inheritAttrs:!1,initialized:!1,documentResizeListener:null,documentMouseMoveListener:null,documentMouseUpListener:null,frame:null,scrollXRatio:null,scrollYRatio:null,isXBarClicked:!1,isYBarClicked:!1,lastPageX:null,lastPageY:null,timer:null,outsideClickListener:null,data:function(){return{id:this.$attrs.id,orientation:"vertical",lastScrollTop:0,lastScrollLeft:0}},watch:{"$attrs.id":function(e){this.id=e||A()}},mounted:function(){this.id=this.id||A(),this.$el.offsetParent&&this.initialize()},updated:function(){!this.initialized&&this.$el.offsetParent&&this.initialize()},beforeUnmount:function(){this.unbindDocumentResizeListener(),this.frame&&window.cancelAnimationFrame(this.frame)},methods:{initialize:function(){this.moveBar(),this.bindDocumentResizeListener(),this.calculateContainerHeight()},calculateContainerHeight:function(){var e=getComputedStyle(this.$el),n=getComputedStyle(this.$refs.xBar),o=O(this.$el)-parseInt(n.height,10);e["max-height"]!=="none"&&o===0&&(this.$refs.content.offsetHeight+parseInt(n.height,10)>parseInt(e["max-height"],10)?this.$el.style.height=e["max-height"]:this.$el.style.height=this.$refs.content.offsetHeight+parseFloat(e.paddingTop)+parseFloat(e.paddingBottom)+parseFloat(e.borderTopWidth)+parseFloat(e.borderBottomWidth)+"px")},moveBar:function(){var e=this;if(this.$refs.content){var n=this.$refs.content.scrollWidth,o=this.$refs.content.clientWidth,d=(this.$el.clientHeight-this.$refs.xBar.clientHeight)*-1;this.scrollXRatio=o/n;var i=this.$refs.content.scrollHeight,r=this.$refs.content.clientHeight,g=(this.$el.clientWidth-this.$refs.yBar.clientWidth)*-1;this.scrollYRatio=r/i,this.frame=this.requestAnimationFrame(function(){e.$refs.xBar&&(e.scrollXRatio>=1?(e.$refs.xBar.setAttribute("data-p-scrollpanel-hidden","true"),!e.isUnstyled&&y(e.$refs.xBar,"p-scrollpanel-hidden")):(e.$refs.xBar.setAttribute("data-p-scrollpanel-hidden","false"),!e.isUnstyled&&w(e.$refs.xBar,"p-scrollpanel-hidden"),e.$refs.xBar.style.cssText="width:"+Math.max(e.scrollXRatio*100,10)+"%; inset-inline-start:"+Math.abs(e.$refs.content.scrollLeft)/n*100+"%;bottom:"+d+"px;")),e.$refs.yBar&&(e.scrollYRatio>=1?(e.$refs.yBar.setAttribute("data-p-scrollpanel-hidden","true"),!e.isUnstyled&&y(e.$refs.yBar,"p-scrollpanel-hidden")):(e.$refs.yBar.setAttribute("data-p-scrollpanel-hidden","false"),!e.isUnstyled&&w(e.$refs.yBar,"p-scrollpanel-hidden"),e.$refs.yBar.style.cssText="height:"+Math.max(e.scrollYRatio*100,10)+"%; top: calc("+e.$refs.content.scrollTop/i*100+"% - "+e.$refs.xBar.clientHeight+"px); inset-inline-end:"+g+"px;"))})}},onYBarMouseDown:function(e){this.isYBarClicked=!0,this.$refs.yBar.focus(),this.lastPageY=e.pageY,this.$refs.yBar.setAttribute("data-p-scrollpanel-grabbed","true"),!this.isUnstyled&&y(this.$refs.yBar,"p-scrollpanel-grabbed"),document.body.setAttribute("data-p-scrollpanel-grabbed","true"),!this.isUnstyled&&y(document.body,"p-scrollpanel-grabbed"),this.bindDocumentMouseListeners(),e.preventDefault()},onXBarMouseDown:function(e){this.isXBarClicked=!0,this.$refs.xBar.focus(),this.lastPageX=e.pageX,this.$refs.yBar.setAttribute("data-p-scrollpanel-grabbed","false"),!this.isUnstyled&&y(this.$refs.xBar,"p-scrollpanel-grabbed"),document.body.setAttribute("data-p-scrollpanel-grabbed","false"),!this.isUnstyled&&y(document.body,"p-scrollpanel-grabbed"),this.bindDocumentMouseListeners(),e.preventDefault()},onScroll:function(e){this.lastScrollLeft!==e.target.scrollLeft?(this.lastScrollLeft=e.target.scrollLeft,this.orientation="horizontal"):this.lastScrollTop!==e.target.scrollTop&&(this.lastScrollTop=e.target.scrollTop,this.orientation="vertical"),this.moveBar()},onKeyDown:function(e){if(this.orientation==="vertical")switch(e.code){case"ArrowDown":{this.setTimer("scrollTop",this.step),e.preventDefault();break}case"ArrowUp":{this.setTimer("scrollTop",this.step*-1),e.preventDefault();break}case"ArrowLeft":case"ArrowRight":{e.preventDefault();break}}else if(this.orientation==="horizontal")switch(e.code){case"ArrowRight":{this.setTimer("scrollLeft",this.step),e.preventDefault();break}case"ArrowLeft":{this.setTimer("scrollLeft",this.step*-1),e.preventDefault();break}case"ArrowDown":case"ArrowUp":{e.preventDefault();break}}},onKeyUp:function(){this.clearTimer()},repeat:function(e,n){this.$refs.content[e]+=n,this.moveBar()},setTimer:function(e,n){var o=this;this.clearTimer(),this.timer=setTimeout(function(){o.repeat(e,n)},40)},clearTimer:function(){this.timer&&clearTimeout(this.timer)},onDocumentMouseMove:function(e){this.isXBarClicked?this.onMouseMoveForXBar(e):this.isYBarClicked?this.onMouseMoveForYBar(e):(this.onMouseMoveForXBar(e),this.onMouseMoveForYBar(e))},onMouseMoveForXBar:function(e){var n=this,o=e.pageX-this.lastPageX;this.lastPageX=e.pageX,this.frame=this.requestAnimationFrame(function(){n.$refs.content.scrollLeft+=o/n.scrollXRatio})},onMouseMoveForYBar:function(e){var n=this,o=e.pageY-this.lastPageY;this.lastPageY=e.pageY,this.frame=this.requestAnimationFrame(function(){n.$refs.content.scrollTop+=o/n.scrollYRatio})},onFocus:function(e){this.$refs.xBar.isSameNode(e.target)?this.orientation="horizontal":this.$refs.yBar.isSameNode(e.target)&&(this.orientation="vertical")},onBlur:function(){this.orientation==="horizontal"&&(this.orientation="vertical")},onDocumentMouseUp:function(){this.$refs.yBar.setAttribute("data-p-scrollpanel-grabbed","false"),!this.isUnstyled&&w(this.$refs.yBar,"p-scrollpanel-grabbed"),this.$refs.xBar.setAttribute("data-p-scrollpanel-grabbed","false"),!this.isUnstyled&&w(this.$refs.xBar,"p-scrollpanel-grabbed"),document.body.setAttribute("data-p-scrollpanel-grabbed","false"),!this.isUnstyled&&w(document.body,"p-scrollpanel-grabbed"),this.unbindDocumentMouseListeners(),this.isXBarClicked=!1,this.isYBarClicked=!1},requestAnimationFrame:function(e){var n=window.requestAnimationFrame||this.timeoutFrame;return n(e)},refresh:function(){this.moveBar()},scrollTop:function(e){var n=this.$refs.content.scrollHeight-this.$refs.content.clientHeight;e=e>n?n:e>0?e:0,this.$refs.content.scrollTop=e},timeoutFrame:function(e){setTimeout(e,0)},bindDocumentMouseListeners:function(){var e=this;this.documentMouseMoveListener||(this.documentMouseMoveListener=function(n){e.onDocumentMouseMove(n)},document.addEventListener("mousemove",this.documentMouseMoveListener)),this.documentMouseUpListener||(this.documentMouseUpListener=function(n){e.onDocumentMouseUp(n)},document.addEventListener("mouseup",this.documentMouseUpListener))},unbindDocumentMouseListeners:function(){this.documentMouseMoveListener&&(document.removeEventListener("mousemove",this.documentMouseMoveListener),this.documentMouseMoveListener=null),this.documentMouseUpListener&&(document.removeEventListener("mouseup",this.documentMouseUpListener),this.documentMouseUpListener=null)},bindDocumentResizeListener:function(){var e=this;this.documentResizeListener||(this.documentResizeListener=function(){e.moveBar()},window.addEventListener("resize",this.documentResizeListener))},unbindDocumentResizeListener:function(){this.documentResizeListener&&(window.removeEventListener("resize",this.documentResizeListener),this.documentResizeListener=null)}},computed:{contentId:function(){return this.id+"_content"}}},ie=["id"],oe=["aria-controls","aria-valuenow"],le=["aria-controls","aria-valuenow"];function ce(t,e,n,o,d,i){return u(),h("div",c({class:t.cx("root")},t.ptmi("root")),[s("div",c({class:t.cx("contentContainer")},t.ptm("contentContainer")),[s("div",c({ref:"content",id:i.contentId,class:t.cx("content"),onScroll:e[0]||(e[0]=function(){return i.onScroll&&i.onScroll.apply(i,arguments)}),onMouseenter:e[1]||(e[1]=function(){return i.moveBar&&i.moveBar.apply(i,arguments)})},t.ptm("content")),[L(t.$slots,"default")],16,ie)],16),s("div",c({ref:"xBar",class:t.cx("barx"),tabindex:"0",role:"scrollbar","aria-orientation":"horizontal","aria-controls":i.contentId,"aria-valuenow":d.lastScrollLeft,onMousedown:e[2]||(e[2]=function(){return i.onXBarMouseDown&&i.onXBarMouseDown.apply(i,arguments)}),onKeydown:e[3]||(e[3]=function(r){return i.onKeyDown(r)}),onKeyup:e[4]||(e[4]=function(){return i.onKeyUp&&i.onKeyUp.apply(i,arguments)}),onFocus:e[5]||(e[5]=function(){return i.onFocus&&i.onFocus.apply(i,arguments)}),onBlur:e[6]||(e[6]=function(){return i.onBlur&&i.onBlur.apply(i,arguments)})},t.ptm("barx"),{"data-pc-group-section":"bar"}),null,16,oe),s("div",c({ref:"yBar",class:t.cx("bary"),tabindex:"0",role:"scrollbar","aria-orientation":"vertical","aria-controls":i.contentId,"aria-valuenow":d.lastScrollTop,onMousedown:e[7]||(e[7]=function(){return i.onYBarMouseDown&&i.onYBarMouseDown.apply(i,arguments)}),onKeydown:e[8]||(e[8]=function(r){return i.onKeyDown(r)}),onKeyup:e[9]||(e[9]=function(){return i.onKeyUp&&i.onKeyUp.apply(i,arguments)}),onFocus:e[10]||(e[10]=function(){return i.onFocus&&i.onFocus.apply(i,arguments)})},t.ptm("bary"),{"data-pc-group-section":"bar"}),null,16,le)],16)}P.render=ce;var ue=function(e){return e.dt,`
.p-scrolltop.p-button {
    position: fixed !important;
    inset-block-end: 20px;
    inset-inline-end: 20px;
}

.p-scrolltop-sticky.p-button {
    position: sticky !important;
    display: flex;
    margin-inline-start: auto;
}

.p-scrolltop-enter-from {
    opacity: 0;
}

.p-scrolltop-enter-active {
    transition: opacity 0.15s;
}

.p-scrolltop.p-scrolltop-leave-to {
    opacity: 0;
}

.p-scrolltop-leave-active {
    transition: opacity 0.15s;
}
`},de={root:function(e){var n=e.props;return["p-scrolltop",{"p-scrolltop-sticky":n.target!=="window"}]},icon:"p-scrolltop-icon"},pe=B.extend({name:"scrolltop",theme:ue,classes:de}),me={name:"BaseScrollTop",extends:M,props:{target:{type:String,default:"window"},threshold:{type:Number,default:400},icon:{type:String,default:void 0},behavior:{type:String,default:"smooth"},buttonProps:{type:Object,default:function(){return{rounded:!0}}}},style:pe,provide:function(){return{$pcScrollTop:this,$parentInstance:this}}},E={name:"ScrollTop",extends:me,inheritAttrs:!1,scrollListener:null,container:null,data:function(){return{visible:!1}},mounted:function(){this.target==="window"?this.bindDocumentScrollListener():this.target==="parent"&&this.bindParentScrollListener()},beforeUnmount:function(){this.target==="window"?this.unbindDocumentScrollListener():this.target==="parent"&&this.unbindParentScrollListener(),this.container&&(z.clear(this.container),this.overlay=null)},methods:{onClick:function(){var e=this.target==="window"?window:this.$el.parentElement;e.scroll({top:0,behavior:this.behavior})},checkVisibility:function(e){e>this.threshold?this.visible=!0:this.visible=!1},bindParentScrollListener:function(){var e=this;this.scrollListener=function(){e.checkVisibility(e.$el.parentElement.scrollTop)},this.$el.parentElement.addEventListener("scroll",this.scrollListener)},bindDocumentScrollListener:function(){var e=this;this.scrollListener=function(){e.checkVisibility(j())},window.addEventListener("scroll",this.scrollListener)},unbindParentScrollListener:function(){this.scrollListener&&(this.$el.parentElement.removeEventListener("scroll",this.scrollListener),this.scrollListener=null)},unbindDocumentScrollListener:function(){this.scrollListener&&(window.removeEventListener("scroll",this.scrollListener),this.scrollListener=null)},onEnter:function(e){z.set("overlay",e,this.$primevue.config.zIndex.overlay)},onAfterLeave:function(e){z.clear(e)},containerRef:function(e){this.container=e?e.$el:void 0}},computed:{scrollTopAriaLabel:function(){return this.$primevue.config.locale.aria?this.$primevue.config.locale.aria.scrollTop:void 0}},components:{ChevronUpIcon:ee,Button:F}};function fe(t,e,n,o,d,i){var r=D("Button");return u(),x(N,c({name:"p-scrolltop",appear:"",onEnter:i.onEnter,onAfterLeave:i.onAfterLeave},t.ptm("transition")),{default:v(function(){return[d.visible?(u(),x(r,c({key:0,ref:i.containerRef,class:t.cx("root"),onClick:i.onClick,"aria-label":i.scrollTopAriaLabel,unstyled:t.unstyled},t.buttonProps,{pt:t.pt}),{icon:v(function(g){return[L(t.$slots,"icon",{class:T(t.cx("icon"))},function(){return[(u(),x(U(t.icon?"span":"ChevronUpIcon"),c({class:[t.cx("icon"),t.icon,g.class]},t.ptm("icon")),null,16,["class"]))]})]}),_:3},16,["class","onClick","aria-label","unstyled","pt"])):C("",!0)]}),_:3},16,["onEnter","onAfterLeave"])}E.render=fe;var ve={root:"p-avatar-group p-component"},he=B.extend({name:"avatargroup",classes:ve}),be={name:"BaseAvatarGroup",extends:M,style:he,provide:function(){return{$pcAvatarGroup:this,$parentInstance:this}}},I={name:"AvatarGroup",extends:be,inheritAttrs:!1};function ge(t,e,n,o,d,i){return u(),h("div",c({class:t.cx("root")},t.ptmi("root")),[L(t.$slots,"default")],16)}I.render=ge;var ye=function(e){var n=e.dt;return`
.p-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: `.concat(n("avatar.width"),`;
    height: `).concat(n("avatar.height"),`;
    font-size: `).concat(n("avatar.font.size"),`;
    background: `).concat(n("avatar.background"),`;
    color: `).concat(n("avatar.color"),`;
    border-radius: `).concat(n("avatar.border.radius"),`;
}

.p-avatar-image {
    background: transparent;
}

.p-avatar-circle {
    border-radius: 50%;
}

.p-avatar-circle img {
    border-radius: 50%;
}

.p-avatar-icon {
    font-size: `).concat(n("avatar.icon.size"),`;
    width: `).concat(n("avatar.icon.size"),`;
    height: `).concat(n("avatar.icon.size"),`;
}

.p-avatar img {
    width: 100%;
    height: 100%;
}

.p-avatar-lg {
    width: `).concat(n("avatar.lg.width"),`;
    height: `).concat(n("avatar.lg.width"),`;
    font-size: `).concat(n("avatar.lg.font.size"),`;
}

.p-avatar-lg .p-avatar-icon {
    font-size: `).concat(n("avatar.lg.icon.size"),`;
    width: `).concat(n("avatar.lg.icon.size"),`;
    height: `).concat(n("avatar.lg.icon.size"),`;
}

.p-avatar-xl {
    width: `).concat(n("avatar.xl.width"),`;
    height: `).concat(n("avatar.xl.width"),`;
    font-size: `).concat(n("avatar.xl.font.size"),`;
}

.p-avatar-xl .p-avatar-icon {
    font-size: `).concat(n("avatar.xl.icon.size"),`;
    width: `).concat(n("avatar.xl.icon.size"),`;
    height: `).concat(n("avatar.xl.icon.size"),`;
}

.p-avatar-group {
    display: flex;
    align-items: center;
}

.p-avatar-group .p-avatar + .p-avatar {
    margin-inline-start: `).concat(n("avatar.group.offset"),`;
}

.p-avatar-group .p-avatar {
    border: 2px solid `).concat(n("avatar.group.border.color"),`;
}

.p-avatar-group .p-avatar-lg + .p-avatar-lg {
    margin-inline-start: `).concat(n("avatar.lg.group.offset"),`;
}

.p-avatar-group .p-avatar-xl + .p-avatar-xl {
    margin-inline-start: `).concat(n("avatar.xl.group.offset"),`;
}
`)},we={root:function(e){var n=e.props;return["p-avatar p-component",{"p-avatar-image":n.image!=null,"p-avatar-circle":n.shape==="circle","p-avatar-lg":n.size==="large","p-avatar-xl":n.size==="xlarge"}]},label:"p-avatar-label",icon:"p-avatar-icon"},Be=B.extend({name:"avatar",theme:ye,classes:we}),Le={name:"BaseAvatar",extends:M,props:{label:{type:String,default:null},icon:{type:String,default:null},image:{type:String,default:null},size:{type:String,default:"normal"},shape:{type:String,default:"square"},ariaLabelledby:{type:String,default:null},ariaLabel:{type:String,default:null}},style:Be,provide:function(){return{$pcAvatar:this,$parentInstance:this}}},R={name:"Avatar",extends:Le,inheritAttrs:!1,emits:["error"],methods:{onError:function(e){this.$emit("error",e)}}},$e=["aria-labelledby","aria-label"],xe=["src","alt"];function Me(t,e,n,o,d,i){return u(),h("div",c({class:t.cx("root"),"aria-labelledby":t.ariaLabelledby,"aria-label":t.ariaLabel},t.ptmi("root")),[L(t.$slots,"default",{},function(){return[t.label?(u(),h("span",c({key:0,class:t.cx("label")},t.ptm("label")),G(t.label),17)):t.$slots.icon?(u(),x(U(t.$slots.icon),{key:1,class:T(t.cx("icon"))},null,8,["class"])):t.icon?(u(),h("span",c({key:2,class:[t.cx("icon"),t.icon]},t.ptm("icon")),null,16)):t.image?(u(),h("img",c({key:3,src:t.image,alt:t.ariaLabel,onError:e[0]||(e[0]=function(){return i.onError&&i.onError.apply(i,arguments)})},t.ptm("image")),null,16,xe)):C("",!0)]})],16,$e)}R.render=Me;var ze=function(e){var n=e.dt;return`
.p-overlaybadge {
    position: relative;
}

.p-overlaybadge .p-badge {
    position: absolute;
    inset-block-start: 0;
    inset-inline-end: 0;
    transform: translate(50%, -50%);
    transform-origin: 100% 0;
    margin: 0;
    outline-width: `.concat(n("overlaybadge.outline.width"),`;
    outline-style: solid;
    outline-color: `).concat(n("overlaybadge.outline.color"),`;
}

.p-overlaybadge .p-badge:dir(rtl) {
    transform: translate(-50%, -50%);
}
`)},Se={root:"p-overlaybadge"},ke=B.extend({name:"overlaybadge",theme:ze,classes:Se}),Ae={name:"OverlayBadge",extends:S,style:ke,provide:function(){return{$pcOverlayBadge:this,$parentInstance:this}}},X={name:"OverlayBadge",extends:Ae,inheritAttrs:!1,components:{Badge:S}};function De(t,e,n,o,d,i){var r=D("Badge");return u(),h("div",c({class:t.cx("root")},t.ptmi("root")),[L(t.$slots,"default"),a(r,c(t.$props,{pt:t.ptm("pcBadge")}),null,16,["pt"])],16)}X.render=De;const Te={class:"card"},Ue={class:"flex flex-col md:flex-row gap-4"},Ce={class:"md:w-1/2"},Fe={class:"md:w-1/2"},Pe={class:"flex flex-col md:flex-row gap-8"},Ee={class:"md:w-1/2"},Ie={class:"card"},Re={class:"flex gap-2"},Xe={class:"flex gap-6"},Ye={class:"flex gap-2"},qe={class:"flex items-start gap-2"},He={class:"card"},Ke={class:"card"},Ve={class:"md:w-1/2"},We={class:"card"},Oe={class:"flex gap-2"},je={class:"flex gap-2"},Ne={class:"flex gap-2"},Ge={class:"card"},Je={class:"flex items-center flex-col sm:flex-row"},Ze={class:"flex items-center flex-col sm:flex-row"},Qe={class:"flex items-center flex-col sm:flex-row"},_e={class:"card"},et={class:"rounded-border border border-surface p-6"},tt={class:"flex mb-4"},nt={class:"flex justify-between mt-4"},pt={__name:"MiscDoc",setup(t){const e=J(0);let n=null;function o(){n=setInterval(()=>{let i=e.value+Math.floor(Math.random()*10)+1;i>=100&&(i=100),e.value=i},2e3)}function d(){clearInterval(n),n=null}return Z(()=>{o()}),Q(()=>{d()}),(i,r)=>{const g=te,f=S,$=X,k=F,m=R,Y=I,q=E,H=P,l=W,p=V,b=K;return u(),h(_,null,[s("div",Te,[r[0]||(r[0]=s("div",{class:"font-semibold text-xl mb-4"},"ProgressBar",-1)),s("div",Ue,[s("div",Ce,[a(g,{value:e.value},null,8,["value"])]),s("div",Fe,[a(g,{value:50,showValue:!1})])])]),s("div",Pe,[s("div",Ee,[s("div",Ie,[r[4]||(r[4]=s("div",{class:"font-semibold text-xl mb-4"},"Badge",-1)),s("div",Re,[a(f,{value:2}),a(f,{value:8,severity:"success"}),a(f,{value:4,severity:"info"}),a(f,{value:12,severity:"Warn"}),a(f,{value:3,severity:"danger"})]),r[5]||(r[5]=s("div",{class:"font-semibold my-4"},"Overlay",-1)),s("div",Xe,[a($,{value:"2"},{default:v(()=>r[1]||(r[1]=[s("i",{class:"pi pi-bell",style:{"font-size":"2rem"}},null,-1)])),_:1}),a($,{value:"4",severity:"danger"},{default:v(()=>r[2]||(r[2]=[s("i",{class:"pi pi-calendar",style:{"font-size":"2rem"}},null,-1)])),_:1}),a($,{severity:"danger"},{default:v(()=>r[3]||(r[3]=[s("i",{class:"pi pi-envelope",style:{"font-size":"2rem"}},null,-1)])),_:1})]),r[6]||(r[6]=s("div",{class:"font-semibold my-4"},"Button",-1)),s("div",Ye,[a(k,{label:"Emails",badge:"8",class:"mr-2"}),a(k,{label:"Messages",icon:"pi pi-users",severity:"warn",badge:"8",badgeClass:"p-badge-danger"})]),r[7]||(r[7]=s("div",{class:"font-semibold my-4"},"Sizes",-1)),s("div",qe,[a(f,{value:2}),a(f,{value:4,size:"large",severity:"warn"}),a(f,{value:6,size:"xlarge",severity:"success"})])]),s("div",He,[r[8]||(r[8]=s("div",{class:"font-semibold text-xl mb-4"},"Avatar",-1)),r[9]||(r[9]=s("div",{class:"font-semibold mb-4"},"Group",-1)),a(Y,null,{default:v(()=>[a(m,{image:"/demo/images/avatar/amyelsner.png",size:"large",shape:"circle"}),a(m,{image:"/demo/images/avatar/asiyajavayant.png",size:"large",shape:"circle"}),a(m,{image:"/demo/images/avatar/onyamalimba.png",size:"large",shape:"circle"}),a(m,{image:"/demo/images/avatar/ionibowcher.png",size:"large",shape:"circle"}),a(m,{image:"/demo/images/avatar/xuxuefeng.png",size:"large",shape:"circle"}),a(m,{label:"+2",shape:"circle",size:"large",style:{"background-color":"#9c27b0",color:"#ffffff"}})]),_:1}),r[10]||(r[10]=s("div",{class:"font-semibold my-4"},"Label - Circle",-1)),a(m,{label:"P",class:"mr-2",size:"xlarge",shape:"circle"}),a(m,{label:"V",class:"mr-2",size:"large",style:{"background-color":"#2196F3",color:"#ffffff"},shape:"circle"}),a(m,{label:"U",class:"mr-2",style:{"background-color":"#9c27b0",color:"#ffffff"},shape:"circle"}),r[11]||(r[11]=s("div",{class:"font-semibold my-4"},"Icon - Badge",-1)),a($,{value:"4",severity:"danger",class:"inline-flex"},{default:v(()=>[a(m,{label:"U",size:"xlarge"})]),_:1})]),s("div",Ke,[r[13]||(r[13]=s("div",{class:"font-semibold text-xl mb-4"},"ScrollTop",-1)),a(H,{style:{width:"250px",height:"200px"}},{default:v(()=>[r[12]||(r[12]=s("p",null," Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Vitae et leo duis ut diam. Ultricies mi quis hendrerit dolor magna eget est lorem. Amet consectetur adipiscing elit ut. Nam libero justo laoreet sit amet. Pharetra massa massa ultricies mi quis hendrerit dolor magna. Est ultricies integer quis auctor elit sed vulputate. Consequat ac felis donec et. Tellus orci ac auctor augue mauris. Semper feugiat nibh sed pulvinar proin gravida hendrerit lectus a. Tincidunt arcu non sodales neque sodales. Metus aliquam eleifend mi in nulla posuere sollicitudin aliquam ultrices. Sodales ut etiam sit amet nisl purus. Cursus sit amet dictum sit amet. Tristique senectus et netus et malesuada fames ac turpis egestas. Et tortor consequat id porta nibh venenatis cras sed. Diam maecenas ultricies mi eget mauris. Eget egestas purus viverra accumsan in nisl nisi. Suscipit adipiscing bibendum est ultricies integer. Mattis aliquam faucibus purus in massa tempor nec. ",-1)),a(q,{target:"parent",threshold:100,icon:"pi pi-arrow-up"})]),_:1})])]),s("div",Ve,[s("div",We,[r[14]||(r[14]=s("div",{class:"font-semibold text-xl mb-4"},"Tag",-1)),r[15]||(r[15]=s("div",{class:"font-semibold mb-4"},"Default",-1)),s("div",Oe,[a(l,{value:"Primary"}),a(l,{severity:"success",value:"Success"}),a(l,{severity:"info",value:"Info"}),a(l,{severity:"warn",value:"Warn"}),a(l,{severity:"danger",value:"Danger"})]),r[16]||(r[16]=s("div",{class:"font-semibold my-4"},"Pills",-1)),s("div",je,[a(l,{value:"Primary",rounded:!0}),a(l,{severity:"success",value:"Success",rounded:!0}),a(l,{severity:"info",value:"Info",rounded:!0}),a(l,{severity:"warn",value:"Warn",rounded:!0}),a(l,{severity:"danger",value:"Danger",rounded:!0})]),r[17]||(r[17]=s("div",{class:"font-semibold my-4"},"Icons",-1)),s("div",Ne,[a(l,{icon:"pi pi-user",value:"Primary"}),a(l,{icon:"pi pi-check",severity:"success",value:"Success"}),a(l,{icon:"pi pi-info-circle",severity:"info",value:"Info"}),a(l,{con:"pi pi-exclamation-triangle",severity:"warn",value:"Warn"}),a(l,{icon:"pi pi-times",severity:"danger",value:"Danger"})])]),s("div",Ge,[r[18]||(r[18]=s("div",{class:"font-semibold text-xl mb-4"},"Chip",-1)),r[19]||(r[19]=s("div",{class:"font-semibold mb-4"},"Basic",-1)),s("div",Je,[a(p,{label:"Action",class:"mr-2 mb-2"}),a(p,{label:"Comedy",class:"mr-2 mb-2"}),a(p,{label:"Mystery",class:"mr-2 mb-2"}),a(p,{label:"Thriller",removable:!0,class:"mb-2"})]),r[20]||(r[20]=s("div",{class:"font-semibold my-4"},"Icon",-1)),s("div",Ze,[a(p,{label:"Apple",icon:"pi pi-apple",class:"mr-2 mb-2"}),a(p,{label:"Facebook",icon:"pi pi-facebook",class:"mr-2 mb-2"}),a(p,{label:"Google",icon:"pi pi-google",class:"mr-2 mb-2"}),a(p,{label:"Microsoft",icon:"pi pi-microsoft",removable:!0,class:"mb-2"})]),r[21]||(r[21]=s("div",{class:"font-semibold my-4"},"Image",-1)),s("div",Qe,[a(p,{label:"Amy Elsner",image:"/demo/images/avatar/amyelsner.png",class:"mr-2 mb-2"}),a(p,{label:"Asiya Javayant",image:"/demo/images/avatar/asiyajavayant.png",class:"mr-2 mb-2"}),a(p,{label:"Onyama Limba",image:"/demo/images/avatar/onyamalimba.png",class:"mr-2 mb-2"})])]),s("div",_e,[r[22]||(r[22]=s("div",{class:"font-semibold text-xl mb-4"},"Skeleton",-1)),s("div",et,[s("div",tt,[a(b,{shape:"circle",size:"4rem",class:"mr-2"}),s("div",null,[a(b,{width:"10rem",class:"mb-2"}),a(b,{width:"5rem",class:"mb-2"}),a(b,{height:".5rem"})])]),a(b,{width:"100%",height:"150px"}),s("div",nt,[a(b,{width:"4rem",height:"2rem"}),a(b,{width:"4rem",height:"2rem"})])])])])])],64)}}};export{pt as default};
