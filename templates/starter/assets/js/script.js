(function(){'use strict';
var h=document.getElementById('hamburger'),c=document.getElementById('closeMenu'),n=document.getElementById('navbar');
if(h&&n){h.addEventListener('click',function(){n.classList.add('nav-open');document.body.style.overflow='hidden';});}
if(c&&n){c.addEventListener('click',function(){n.classList.remove('nav-open');document.body.style.overflow='';});}
var t=document.getElementById('themeSelect');
if(t){var s=localStorage.getItem('site-theme');if(s==='dark'||s==='light')t.value=s;t.addEventListener('change',function(){var v=this.value,h=document.documentElement;h.classList.remove('theme-dark','theme-light');h.classList.add('theme-'+v);h.setAttribute('data-current-theme',v);localStorage.setItem('site-theme',v);document.cookie='site-theme='+v+'; path=/; max-age='+(60*60*24*365);});}
if(n){n.querySelectorAll('a').forEach(function(a){a.addEventListener('click',function(){n.classList.remove('nav-open');document.body.style.overflow='';});});}
})();
