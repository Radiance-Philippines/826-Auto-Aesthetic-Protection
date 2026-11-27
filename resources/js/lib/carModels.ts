/**
 * Suggestions for "Make and model": units commonly seen on Philippine roads.
 * Only a typing aid. Anything else can be typed, so the list needn't be complete.
 */
const BY_MAKE: Record<string, string> = {
    Toyota: 'Wigo, Vios, Yaris Cross, Corolla Altis, Corolla Cross, Camry, Raize, Rush, Avanza, Veloz, Innova, Zenix, Fortuner, Hilux, Hiace, Lite Ace, Tamaraw, RAV4, Land Cruiser, Land Cruiser Prado, Alphard, GR86, Supra',
    Mitsubishi: 'Mirage, Mirage G4, Xpander, Xpander Cross, Xforce, Montero Sport, Strada, Triton, L300, Pajero',
    Honda: 'Brio, City, City Hatchback, Civic, Accord, BR-V, HR-V, CR-V, Odyssey, Click 125, Beat, PCX 160, ADV 160',
    Nissan: 'Almera, Kicks, Livina, Terra, Navara, Urvan, Patrol, X-Trail',
    Ford: 'Ranger, Ranger Raptor, Everest, Territory, Explorer, Mustang',
    Suzuki: 'Celerio, S-Presso, Swift, Dzire, Ertiga, XL7, Jimny, Carry',
    Hyundai: 'Accent, Reina, Elantra, Stargazer, Creta, Tucson, Santa Fe, Staria, Palisade',
    Kia: 'Picanto, Soluto, Stonic, Sonet, Seltos, Sportage, Sorento, Carnival',
    Mazda: '2, 3, CX-3, CX-30, CX-5, CX-8, CX-9, CX-60, BT-50, MX-5',
    Isuzu: 'D-Max, mu-X, Traviz',
    Chevrolet: 'Colorado, Trailblazer',
    Subaru: 'XV, Crosstrek, Forester, Outback, WRX, BRZ',
    MG: '5, ZS, HS',
    Geely: 'Coolray, Okavango, Emgrand, Azkarra',
    BYD: 'Atto 3, Dolphin, Seal, Sealion 6',
    Chery: 'Tiggo 7 Pro, Tiggo 8 Pro',
    GAC: 'GS3 Emzoom, GS8',
    Jetour: 'Dashing, T2, X70',
    BMW: '3 Series, 5 Series, 7 Series, X1, X3, X5',
    'Mercedes-Benz': 'A-Class, C-Class, E-Class, GLA, GLC, GLE',
    Lexus: 'IS, ES, NX, RX, GX, LX, LM',
    Audi: 'A4, Q3, Q5',
    Porsche: '911, Macan, Cayenne',
    'Land Rover': 'Defender, Discovery, Range Rover, Range Rover Sport',
    Jeep: 'Wrangler',
    Tesla: 'Model 3, Model Y',
    Yamaha: 'Mio, NMAX, Aerox',
};

export const carModels: string[] = Object.entries(BY_MAKE).flatMap(([make, models]) => models.split(', ').map((model) => `${make} ${model}`));
