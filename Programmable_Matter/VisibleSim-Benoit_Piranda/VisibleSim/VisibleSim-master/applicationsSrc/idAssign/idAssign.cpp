/**
 * @file idAssign.cpp
 **/
#include <iostream>
#include "idAssignCode.hpp"

using namespace std;
using namespace BlinkyBlocks;
using namespace IdAssign;

int main(int argc, char **argv) {
    try {
        createSimulator(argc, argv, IdAssignCode::buildNewBlockCode);
        getSimulator()->printInfo();
        BaseSimulator::getWorld()->printInfo();
        deleteSimulator();
    } catch(std::exception const& e) {
        cerr << "Uncaught exception: " << e.what();
    }
    return 0;
}
