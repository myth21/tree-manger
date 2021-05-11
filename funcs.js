function getRandomString(i = 10) {
    let str = '';
    while (str.length < i) str += Math.random().toString(36).substring(2);
    return str.substring(0, i);
}

function getRandomInteger() {
    let randomNumber = Math.random();
    let numberLength = randomNumber.toString().split('.').pop().length;
    let zero = '';
    for (let i = 0; i < numberLength; i++) zero += 0;
    let number = 1 + '' + zero;
    return Math.round(randomNumber * parseInt(number));
}