const notice = document.querySelector('#notice');
let csrfToken = '';
let refreshTimer;
let membershipState = null;
let membershipLoaded = false;
let availablePlans = [];
let cardPaymentAvailable = false;
const t = (key, fallback) => window.smartGymTranslations?.[document.documentElement.lang]?.[key] || fallback;
const $ = (selector) => document.querySelector(selector);
function setNotice(text) { notice.textContent = text; notice.classList.toggle('show', Boolean(text)); }
async function getJson(url, options = {}) { const result = await window.apiClient(url, options); return { ...(result.data || {}), message: result.message }; }
function formatDate(value) { if (!value) return '—'; const [year, month, day] = value.slice(0, 10).split('-').map(Number); return new Intl.DateTimeFormat(document.documentElement.lang, { month:'short', day:'numeric', year:'numeric', timeZone:'UTC' }).format(new Date(Date.UTC(year, month - 1, day))); }
function empty(container, text) { const p=document.createElement('p'); p.className='muted'; p.textContent=text; container.replaceChildren(p); }
function translatedPlan(name){return name==='Monthly Starter'?t('monthlyName',name):name==='3 Months Bundle'?t('quarterName',name):name==='Annual VIP'?t('annualName',name):name;}
function translatedType(name){const value=(name||'').toLowerCase();return value.includes('crossfit')?t('crossfitTitle','CrossFit'):value.includes('hiit')?t('hiitTitle','HIIT & Cardio'):value.includes('boxing')?t('boxingTitle','Combat Boxing'):value.includes('yoga')?t('yogaTitle','Power Yoga'):name;}
function renderBookings(bookings) {
 const list=$('#booking-list'); list.replaceChildren();
 if(!bookings.length){empty(list,t('memberNoBookings','No booked classes yet. Browse the schedule to reserve a seat.'));return;}
 for(const item of bookings){const card=document.createElement('article');card.className='booking-card';const title=document.createElement('h3');title.textContent=item.title;const type=document.createElement('span');type.className='promo-badge';type.textContent=translatedType(item.class_type);const meta=document.createElement('p');meta.className='booking-meta';meta.textContent=`${item.trainer_first_name} ${item.trainer_last_name} · ${new Date(`${item.starts_at.replace(' ','T')}Z`).toLocaleString()}`;const state=document.createElement('p');state.className='booking-meta';state.textContent=item.booking_status==='ATTENDED'?t('memberAttended','Attended'):item.booking_status==='CANCELLED'?t('memberCancelled','Cancelled'):t('memberBooked','Booked');card.append(type,title,meta,state);if(item.booking_status==='BOOKED'&&new Date(`${item.starts_at.replace(' ','T')}Z`)>new Date()){const button=document.createElement('button');button.className='secondary-button';button.textContent=t('memberCancelBooking','Cancel booking');button.addEventListener('click',async()=>{if(!window.confirm(t('memberConfirmCancel','Cancel this class booking?')))return;button.disabled=true;try{await getJson('api/classes/cancel-booking.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({class_id:item.class_id})});await loadDashboard();}catch(error){setNotice(error.message);button.disabled=false;}});card.append(button);}list.append(card);}
}
function renderPayments(payments){const body=$('#payment-list');body.replaceChildren();if(!payments.length){const row=body.insertRow();const cell=row.insertCell();cell.colSpan=6;cell.className='muted';cell.textContent=t('memberNoPayments','No payments recorded yet.');return;}for(const pay of payments){const row=body.insertRow();const method=pay.method==='CASH'?t('staffCash','Cash'):pay.method==='CARD'?t('staffCard','Card'):pay.method==='BANK_TRANSFER'?t('bankTransfer','Bank transfer'):pay.method;const state=pay.status==='COMPLETED'?t('paymentCompleted','Paid'):pay.status==='REFUNDED'?t('paymentRefunded','Refunded'):t('paymentVoided','Voided');[pay.id,translatedPlan(pay.plan_name)||'—',`${Number(pay.amount).toFixed(2)} ${pay.currency}`,method,formatDate(pay.paid_at),state].forEach(value=>row.insertCell().textContent=String(value));}}
function renderPlanOptions(){
 const section=$('#plans'),nav=$('#member-plans-nav'),list=$('#plan-list');
 const active=membershipLoaded&&membershipState&&['ACTIVE','EXPIRING'].includes(String(membershipState.status).toUpperCase());
 const pending=membershipLoaded&&membershipState&&String(membershipState.status).toUpperCase()==='PENDING';
 section.hidden=!membershipLoaded||active;
 nav.hidden=!membershipLoaded||active;
 list.replaceChildren();
 if(!membershipLoaded||active)return;
 if(pending){empty(list,t('memberPendingMembershipNotice','Your membership request is being reviewed. Plan purchases are unavailable until it is resolved.'));return;}
 if(!availablePlans.length){empty(list,t('memberNoPlans','No membership plans are available.'));return;}
 for(const plan of availablePlans){
  const card=document.createElement('article');card.className='plan-card';
  if(plan.promo_label){const badge=document.createElement('span');badge.className='promo-badge';badge.textContent=t('memberFreeMonth','FREE MONTH OFFER');card.append(badge);}
  const title=document.createElement('h3');title.textContent=translatedPlan(plan.title);
  const price=document.createElement('div');price.className='plan-price';
  if(plan.original_price){const old=document.createElement('del');old.textContent=`${plan.original_price} ${plan.currency}`;price.append(old);}
  const amount=document.createElement('strong');amount.textContent=`${Number(plan.price).toFixed(0)} ${plan.currency}`;price.append(amount);
  const duration=document.createElement('p');duration.className='booking-meta';duration.textContent=`${plan.duration_days} ${t('days','days')}`;
  const details=document.createElement('p');details.className='plan-description';
  const featureKeys=plan.duration_days===30?['monthlyFeature1','monthlyFeature2','monthlyFeature3','monthlyFeature4']:plan.duration_days===90?['quarterFeature1','quarterFeature2','quarterFeature3','quarterFeature4']:plan.duration_days===365?['annualFeature1','annualFeature2','annualFeature3','annualFeature4']:[];
  details.textContent=featureKeys.length?featureKeys.map(key=>t(key,'')).filter(Boolean).join(' · '):(plan.description||plan.includes_info||'');
  const actions=document.createElement('div');actions.className='plan-payment-actions';
  const reception=document.createElement('button');reception.className='secondary-button';reception.textContent=t('memberPayAtReception','Request plan · pay at reception');
  reception.addEventListener('click',()=>submitPlanRequest(plan.id,'RECEPTION',reception));
  const cardButton=document.createElement('button');cardButton.className='primary-button';cardButton.textContent=t('memberPayByCard','Pay by card');cardButton.disabled=!cardPaymentAvailable;
  cardButton.title=cardPaymentAvailable?'':t('memberCardUnavailable','Card payment is not configured yet.');
  cardButton.addEventListener('click',()=>submitPlanRequest(plan.id,'CARD',cardButton));
  const cardHint=document.createElement('p');cardHint.className='plan-description';cardHint.textContent=cardPaymentAvailable?t('memberSecureCheckout','You will continue to the provider’s secure checkout.'):(document.documentElement.lang==='ar'?'الدفع بالبطاقة سيُتاح بعد ربط مزود الدفع.':'Card payment will be available after a provider is connected.');
  actions.append(reception,cardButton);card.append(title,price,duration,details,actions,cardHint);list.append(card);
 }
}
async function submitPlanRequest(planId,paymentMethod,button){button.disabled=true;try{const result=await getJson('api/member/renew.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({plan_id:planId,payment_method:paymentMethod})});if(result.online_available){const checkout=await getJson('api/member/payment-checkout.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({membership_id:result.membership_id})});window.location.assign(checkout.checkout_url);return;}setNotice(result.message);await loadDashboard();}catch(error){setNotice(error.message);button.disabled=false;}}
async function loadPlans(){const data=await getJson('api/member/plans.php');availablePlans=data.plans||[];cardPaymentAvailable=Boolean(data.card_payment_available);renderPlanOptions();}
async function loadDashboard(){const data=await getJson('api/member/dashboard.php');const user=data.user;$('#greeting').textContent=`${t('memberWelcome','Welcome back')}, ${user.first_name}`;$('#welcome').textContent=`${user.first_name} ${user.last_name}`;$('#member-avatar').textContent=(user.first_name||'M').slice(0,1).toUpperCase();const m=data.membership;membershipState=m;membershipLoaded=true;renderPlanOptions();const chip=$('#member-status'),status=$('#plan-status');if(!m){chip.textContent=t('memberNoMembership','NO MEMBERSHIP');chip.className='member-chip inactive';$('#plan-name').textContent=t('memberNoCurrentPlan','No current membership');$('#plan-description').textContent=t('memberChoosePlan','Choose a plan below and submit a request.');$('#days-remaining').textContent='0';status.textContent=t('inactive','INACTIVE');status.className='status inactive';$('#starts-on').textContent='—';$('#ends-on').textContent='—';$('#progress-bar').style.width='0%';}else{const s=String(m.status).toUpperCase();const label=t(`membershipStatus${s}`,s);chip.textContent=label;chip.className=`member-chip ${s.toLowerCase()}`;status.textContent=label;status.className=`status ${s.toLowerCase()}`;$('#plan-name').textContent=m.plan_name;$('#plan-description').textContent=m.plan_description||t('memberPlanReady','Your membership details.');$('#days-remaining').textContent=m.days_remaining;$('#starts-on').textContent=formatDate(m.starts_on);$('#ends-on').textContent=formatDate(m.ends_on);const total=Math.max(1,Math.ceil((Date.parse(`${m.ends_on}T00:00:00Z`)-Date.parse(`${m.starts_on}T00:00:00Z`))/86400000)+1);const elapsed=Math.max(0,total-m.days_remaining);$('#progress-bar').style.width=`${Math.min(100,elapsed/total*100)}%`;}
$('#upcoming-bookings').textContent=data.activity?.upcoming_bookings||0;$('#attended-sessions').textContent=data.activity?.attended_sessions||0;renderBookings(data.bookings||[]);renderPayments(data.payments||[]);}
async function start(){try{const session=await getJson('api/auth/session.php');csrfToken=session.csrf_token;if(!session.user){window.navigateWithLoader('login.html');return;}if(session.user.role!=='MEMBER'){window.navigateWithLoader(session.user.role==='ADMIN'?'admin.html':session.user.role==='TRAINER'?'trainer.html':'staff.html');return;}await Promise.all([loadDashboard(),loadPlans()]);clearTimeout(refreshTimer);refreshTimer=setTimeout(start,60000);}catch(error){setNotice(error.message);}finally{document.querySelector('[data-skeleton-screen]')?.classList.add('is-hidden');document.querySelector('[data-skeleton-screen]')?.setAttribute('aria-hidden','true');}}
$('#logout').addEventListener('click',async event=>{const button=event.currentTarget;button.disabled=true;try{await getJson('api/auth/logout.php',{method:'POST',headers:{'X-CSRF-Token':csrfToken}});window.navigateWithLoader('login.html');}catch(error){setNotice(error.message);button.disabled=false;}});
window.addEventListener('smart-gym-language-change',()=>{loadDashboard().catch(error=>setNotice(error.message));loadPlans().catch(error=>setNotice(error.message));});
start();
