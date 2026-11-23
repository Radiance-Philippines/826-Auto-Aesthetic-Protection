/** 1284500 -> "₱1,284,500"; amounts come from the server, never from the app */
export const peso = (amount: number): string => `₱${Math.round(amount).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',')}`;
