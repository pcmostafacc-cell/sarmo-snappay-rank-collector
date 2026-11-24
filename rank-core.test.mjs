import assert from 'node:assert/strict';
import { normalizeFa, ownsProduct, summarizeRank } from './rank-core.mjs';

assert.equal(normalizeFa('سارمو‌ استايل'), 'سارمو استایل');
const identity = { merchantAliases: ['سارمو استایل'], productKeys: [] };
const products = [
  { merchant: 'رقیب', title: 'روسری ۱', image: '1', isAd: true },
  { merchant: 'شاپینو', title: 'روسری سارمو', image: '2', isAd: false },
  { merchant: 'سارمو استایل', title: 'روسری ۲', image: '3', isAd: true },
];
assert.equal(ownsProduct(products[1], identity), false);
const rank = summarizeRank({
  query: 'روسری', displayedQuery: 'روسری', observedAt: '2026-11-24T00:00:00Z',
  merchants: ['رقیب', 'سارمو استایل'], banners: ['رقیب'],
  products, sequenceReliable: true, scanComplete: false,
}, identity);
assert.equal(rank.gridRank, 3);
assert.equal(rank.adRank, 2);
assert.equal(rank.merchantCarouselRank, 2);
assert.equal(rank.bannerRank, null);
assert.equal(rank.scanComplete, false);
assert.equal(rank.sourceValid, true);
assert.equal(summarizeRank({query:'عبا',displayedQuery:'روسری',products,sequenceReliable:true},identity).sourceValid,false);
assert.equal(summarizeRank({
  query:'روسری',displayedQuery:'روسری',products:products.slice(0,2),
  sequenceReliable:true,scanComplete:false,
},identity).sourceValid,false);
assert.equal(summarizeRank({
  query:'روسری',displayedQuery:'روسری',products,
  sourceWarning:'مشکلی پیش آمده، فیلتر اعمال نشد!',
  sequenceReliable:true,scanComplete:true,
},identity).sourceValid,false);
